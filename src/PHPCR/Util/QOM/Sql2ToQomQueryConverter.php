<?php

/**
 * This file is part of the PHPCR Utils
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License 2.0
 * @link http://phpcr.github.com/
 */

namespace PHPCR\Util\QOM;

use PHPCR\Query\QOM;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface as Constants;

/**
 * Parse SQL2 statements and output a corresponding QOM objects tree
 */
class Sql2ToQomQueryConverter
{
    /**
     * The factory to create QOM objects
     *
     * @var \PHPCR\Query\QOM\QueryObjectModelFactoryInterface
     */
    protected $factory;

    /**
     * Scanner to parse SQL2
     *
     * @var \PHPCR\Util\QOM\Sql2Scanner;
     */
    protected $scanner;

    /**
     * The SQL2 query (the converter is not reentrant)
     *
     * @var string
     */
    protected $sql2;

    /**
     * Instantiate a converter
     *
     * @param \PHPCR\Query\QOM\QueryObjectModelFactoryInterface $factory
     */
    public function __construct(QOM\QueryObjectModelFactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    /**
     * 6.7.1. Query
     * Parse an SQL2 query and return the corresponding QOM QueryObjectModel
     *
     * @param string $sql2
     *
     * @return \PHPCR\Query\QOM\QueryObjectModelInterface;
     */
    public function parse($sql2)
    {
        $this->sql2 = $sql2;
        $this->scanner = new Sql2Scanner($sql2);
        $source = null;
        $columns = array();
        $constraint = null;
        $orderings = array();

        while ($this->scanner->lookupNextToken() !== '') {
            switch (strtoupper($this->scanner->lookupNextToken())) {
                case 'FROM':
                    $source = $this->parseSource();
                    break;
                case 'SELECT':
                    $columns = $this->parseColumns();
                    break;
                case 'ORDER':
                    // Ordering, check there is a BY
                    $this->scanner->expectTokens(array('ORDER', 'BY'));
                    $orderings = $this->parseOrderings();
                    break;
                case 'WHERE':
                    $this->scanner->expectToken('WHERE');
                    $constraint = $this->parseConstraint();
                    break;
                default:
                    // Exit loop for debugging
                    break(2);
            }
        }

        if (!$source instanceof \PHPCR\Query\QOM\SourceInterface) {
            throw new \PHPCR\Query\InvalidQueryException('Invalid query, source could not be determined: '.$sql2);
        }

        $query = $this->factory->createQuery($source, $constraint, $orderings, $columns);

        return $query;
    }

    /**
     * 6.7.2. Source
     * Parse an SQL2 source definition and return the corresponding QOM Source
     *
     * @return \PHPCR\Query\QOM\SourceInterface
     */
    protected function parseSource()
    {
        $this->scanner->expectToken('FROM');

        $selector = $this->parseSelector();

        $next = $this->scanner->lookupNextToken();
        if (in_array(strtoupper($next), array('JOIN', 'INNER', 'RIGHT', 'LEFT'))) {
            return $this->parseJoin($selector);
        }

        return $selector;
    }

    /**
     * 6.7.3. Selector
     * Parse an SQL2 selector and return a QOM\Selector
     *
     * @return \PHPCR\Query\QOM\SelectorInterface
     */
    protected function parseSelector()
    {
        $token = $this->fetchTokenWithoutBrackets();

        if (strtoupper($this->scanner->lookupNextToken()) === 'AS') {
            $this->scanner->fetchNextToken(); // Consume the AS
            $selectorName = $this->parseName();

            return $this->factory->selector($token, $selectorName);
        }

        return $this->factory->selector($token);
    }

    /**
     * 6.7.4. Name
     *
     * @return string
     */
    protected function parseName()
    {
        // TODO: check it's the correct way to parse a JCR name
        return $this->scanner->fetchNextToken();
    }

    /**
     * 6.7.5. Join
     * 6.7.6. Join type
     * Parse an SQL2 join source and return a QOM\Join
     *
     * @param string $leftSelector the left selector as it has been read by parseSource
     * return \PHPCR\Query\QOM\JoinInterface
     */
    protected function parseJoin($leftSelector)
    {
        $joinType = $this->parseJoinType();
        $right = $this->parseSelector();
        $joinCondition = $this->parseJoinCondition();

        return $this->factory->join($leftSelector, $right, $joinType, $joinCondition);
    }

    /**
     * 6.7.6. Join type
     *
     * @return string
     */
    protected function parseJoinType()
    {
        $joinType = Constants::JCR_JOIN_TYPE_INNER;
        $token = $this->scanner->fetchNextToken();

        switch ($token) {
            case 'JOIN':
                // Token already fetched, nothing to do
                break;
            case 'INNER':
                $this->scanner->fetchNextToken();
                break;
            case 'LEFT':
                $this->scanner->expectTokens(array('OUTER', 'JOIN'));
                $joinType = Constants::JCR_JOIN_TYPE_LEFT_OUTER;
                break;
            case 'RIGHT':
                $this->scanner->expectTokens(array('OUTER', 'JOIN'));
                $joinType = Constants::JCR_JOIN_TYPE_RIGHT_OUTER;
                break;
            default:
                throw new \Exception("Syntax error: Expected JOIN, INNER JOIN, RIGHT JOIN or LEFT JOIN in '{$this->sql2}'");
        }

        return $joinType;
    }

    /**
     * 6.7.7. JoinCondition
     * Parse an SQL2 join condition and return a QOM\Joincondition
     *
     * @return \PHPCR\Query\QOM\JoinConditionInterface
     */
    protected function parseJoinCondition()
    {
        $this->scanner->expectToken('ON');

        $token = $this->scanner->lookupNextToken();
        if ($this->scanner->tokenIs($token, 'ISSAMENODE')) {
            return $this->parseSameNodeJoinCondition();
        }

        if ($this->scanner->tokenIs($token, 'ISCHILDNODE')) {
            return $this->parseChildNodeJoinCondition();
        }

        if ($this->scanner->tokenIs($token, 'ISDESCENDANTNODE')) {
            return $this->parseDescendantNodeJoinCondition();
        }

        return $this->parseEquiJoin();
    }

    /**
     * 6.7.8. EquiJoinCondition
     * Parse an SQL2 equijoin condition and return a QOM\EquiJoinCondition
     *
     * @return \PHPCR\Query\QOM\EquiJoinConditionInterface
     */
    protected function parseEquiJoin()
    {
        list($prop1, $selector1) = $this->parseIdentifier();
        $this->scanner->expectToken('=');
        list($prop2, $selector2) = $this->parseIdentifier();

        return $this->factory->equiJoinCondition($selector1, $prop1, $selector2, $prop2);
    }

    /**
     * 6.7.9 SameNodeJoinCondition
     * Parse an SQL2 same node join condition and return a QOM\SameNodeJoinCondition
     *
     * @return \PHPCR\Query\QOM\SameNodeJoinConditionInterface
     */
    protected function parseSameNodeJoinCondition()
    {
        $this->scanner->expectTokens(array('ISSAMENODE', '('));
        $selector1 = $this->parseSelector();
        $this->scanner->expectToken(',');
        $selector2 = $this->parseSelector();

        $token = $this->scanner->lookupNextToken();
        if ($this->scanner->tokenIs($token, ',')) {
            $this->scanner->fetchNextToken(); // consume the coma
            $path = $this->parsePath();
        } else {
            $path = null;
        }

        $this->scanner->expectToken(')');

        return $this->factory->sameNodeJoinCondition($selector1, $selector2, $path);
    }

    /**
     * 6.7.10 ChildNodeJoinCondition
     * Parse an SQL2 child node join condition and return a QOM\ChildNodeJoinCondition
     *
     * @return \PHPCR\Query\QOM\ChildNodeJoinConditionInterface
     */
    protected function parseChildNodeJoinCondition()
    {
        $this->scanner->expectTokens(array('ISCHILDNODE', '('));
        $child = $this->parseSelector();
        $this->scanner->expectToken(',');
        $parent = $this->parseSelector();
        $this->scanner->expectToken(')');

        return $this->factory->childNodeJoinCondition($child, $parent);
    }

    /**
     * 6.7.11 DescendantNodeJoinCondition
     * Parse an SQL2 descendant node join condition and return a QOM\DescendantNodeJoinCondition
     *
     * @return \PHPCR\Query\QOM\DescendantNodeJoinConditionInterface
     */
    protected function parseDescendantNodeJoinCondition()
    {
        $this->scanner->expectTokens(array('ISDESCENDANTNODE', '('));
        $child = $this->parseSelector();
        $this->scanner->expectToken(',');
        $parent = $this->parseSelector();
        $this->scanner->expectToken(')');

        return $this->factory->descendantNodeJoinCondition($child, $parent);
    }

    /**
     * 6.7.12 Constraint
     * 6.7.13 And
     * 6.7.14 Or
     *
     * @return \PHPCR\Query\QOM\ConstraintInterface
     */
    protected function parseConstraint()
    {
        $constraint = null;
        $token = $this->scanner->lookupNextToken();

        if ($this->scanner->tokenIs($token, 'NOT')) {
            // NOT
            $constraint = $this->parseNot();
        } elseif ($this->scanner->tokenIs($token, '(')) {
            // Grouping with parenthesis
            $this->scanner->expectToken('(');
            $constraint = $this->parseConstraint();
            $this->scanner->expectToken(')');
        } elseif ($this->scanner->tokenIs($token, 'CONTAINS')) {
            // Full Text Search
            $constraint = $this->parseFullTextSearch();
        } elseif ($this->scanner->tokenIs($token, 'ISSAMENODE')) {
            // SameNode
            $constraint = $this->parseSameNode();
        } elseif ($this->scanner->tokenIs($token, 'ISCHILDNODE')) {
            // ChildNode
            $constraint = $this->parseChildNode();
        } elseif ($this->scanner->tokenIs($token, 'ISDESCENDANTNODE')) {
            // DescendantNode
            $constraint = $this->parseDescendantNode();
        } else {
            // Is it a property existence?
            $next1 = $this->scanner->lookupNextToken(1);
            if ($this->scanner->tokenIs($next1, 'IS')) {
                $constraint = $this->parsePropertyExistence();
            } elseif ($this->scanner->tokenIs($next1, '.')) {
                $next2 = $this->scanner->lookupNextToken(3);
                if ($this->scanner->tokenIs($next2, 'IS')) {
                    $constraint = $this->parsePropertyExistence();
                }
            }

            if ($constraint === null) {
                // It's not a property existence neither, then it's a comparison
                $constraint = $this->parseComparison();
            }
        }

        // No constraint read,
        if ($constraint === null) {
            throw new \Exception("Syntax error: constraint expected in '{$this->sql2}'");
        }

        // Is it a composed contraint?
        $token = $this->scanner->lookupNextToken();
        if (in_array(strtoupper($token), array('AND', 'OR'))) {
            $this->scanner->fetchNextToken();
            $constraint2 = $this->parseConstraint();
            if ($this->scanner->tokenIs($token, 'AND')) {
                return $this->factory->andConstraint($constraint, $constraint2);
            }

            return $this->factory->orConstraint($constraint, $constraint2);
        }

        return $constraint;
    }

    /**
     * 6.7.15 Not
     *
     * @return \PHPCR\Query\QOM\NotInterface
     */
    protected function parseNot()
    {
        $this->scanner->expectToken('NOT');

        return $this->factory->notConstraint($this->parseConstraint());
    }

    /**
     * 6.7.16 Comparison
     *
     * @return \PHPCR\Query\QOM\ComparisonInterface
     */
    protected function parseComparison()
    {
        $op1 = $this->parseDynamicOperand();

        if (null === $op1) {
            throw new \Exception("Syntax error: dynamic operator expected in '{$this->sql2}'");
        }

        $operator = $this->parseOperator();
        $op2 = $this->parseStaticOperand();

        return $this->factory->comparison($op1, $operator, $op2);
    }

    /**
     * 6.7.17 Operator
     *
     * @return \PHPCR\Query\QOM\OperatorInterface
     */
    protected function parseOperator()
    {
        $token = $this->scanner->fetchNextToken();
        switch (strtoupper($token)) {
            case '=':
                return Constants::JCR_OPERATOR_EQUAL_TO;
            case '<>':
                return Constants::JCR_OPERATOR_NOT_EQUAL_TO;
            case '<':
                return Constants::JCR_OPERATOR_LESS_THAN;
            case '<=':
                return Constants::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO;
            case '>':
                return Constants::JCR_OPERATOR_GREATER_THAN;
            case '>=':
                return Constants::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO;
            case 'LIKE':
                return Constants::JCR_OPERATOR_LIKE;
        }

        throw new \Exception("Syntax error: operator expected in '{$this->sql2}'");
    }

    /**
     * 6.7.18 PropertyExistence
     *
     * @return \PHPCR\Query\QOM\PropertyExistenceInterface
     */
    protected function parsePropertyExistence()
    {
        list($prop, $selector) = $this->parseIdentifier();

        $this->scanner->expectToken('IS');
        $token = $this->scanner->lookupNextToken();
        if ($this->scanner->tokenIs($token, 'NULL')) {
            $this->scanner->fetchNextToken();

            return $this->factory->not($this->factory->propertyExistence($prop, $selector));
        }

        $this->scanner->expectTokens(array('NOT', 'NULL'));

        return $this->factory->propertyExistence($prop, $selector);
    }

    /**
     * 6.7.19 FullTextSearch
     *
     * @return \PHPCR\Query\QOM\FullTextSearchInterface
     */
    protected function parseFullTextSearch()
    {
        $this->scanner->expectTokens(array('CONTAINS', '('));

        list($propertyName, $selectorName) = $this->parseIdentifier();
        $this->scanner->expectToken(',');
        $expression = $this->parseLiteral()->getLiteralValue();
        $this->scanner->expectToken(')');

        return $this->factory->fullTextSearch($propertyName, $expression, $selectorName);
    }

    /**
     * 6.7.20 SameNode
     */
    protected function parseSameNode()
    {
        $this->scanner->expectTokens(array('ISSAMENODE', '('));
        if ($this->scanner->tokenIs($this->scanner->lookupNextToken(1), ',')) {
            $selector = $this->scanner->fetchNextToken();
            $this->scanner->expectToken(',');
            $path = $this->parsePath();
        } else {
            $selector = null;
            $path = $this->parsePath();
        }
        $this->scanner->expectToken(')');

        return $this->factory->sameNode($path, $selector);
    }

    /**
     * 6.7.21 ChildNode
     */
    protected function parseChildNode()
    {
        $this->scanner->expectTokens(array('ISCHILDNODE', '('));
        if ($this->scanner->tokenIs($this->scanner->lookupNextToken(1), ',')) {
            $selector = $this->scanner->fetchNextToken();
            $this->scanner->expectToken(',');
            $path = $this->parsePath();
        } else {
            $selector = null;
            $path = $this->parsePath();
        }
        $this->scanner->expectToken(')');

        return $this->factory->childNode($path, $selector);
    }

    /**
     * 6.7.22 DescendantNode
     */
    protected function parseDescendantNode()
    {
        $this->scanner->expectTokens(array('ISDESCENDANTNODE', '('));
        if ($this->scanner->tokenIs($this->scanner->lookupNextToken(1), ',')) {
            $selector = $this->scanner->fetchNextToken();
            $this->scanner->expectToken(',');
            $path = $this->parsePath();
        } else {
            $selector = null;
            $path = $this->parsePath();
        }
        $this->scanner->expectToken(')');

        return $this->factory->descendantNode($path, $selector);
    }

    /**
     * Parse a JCR path consisting of either a simple path (a JCR name that contains
     * only SQL-legal characters) or a path (simple path or quoted path) enclosed in
     * square brackets. See JCR Spec § 6.7.23.
     *
     * 6.7.23. Path
     */
    protected function parsePath()
    {
        $path = $this->parseLiteral()->getLiteralValue();
        if (substr($path, 0, 1) === '[' && substr($path, -1) === ']') {
            $path = substr($path, 1, -1);
        }

        return $path;
    }

    /**
     * Parse an SQL2 static operand
     * 6.7.35 BindVariable
     * 6.7.36 Prefix
     *
     * @return \PHPCR\Query\QOM\StaticOperandInterface
     */
    protected function parseStaticOperand()
    {
        $token = $this->scanner->lookupNextToken();
        if (substr($token, 0, 1) === '$') {
            return $this->factory->bindVariable(substr($token, 1));
        }

        return $this->parseLiteral();
    }

    /**
     * 6.7.26 DynamicOperand
     * 6.7.28 Length
     * 6.7.29 NodeName
     * 6.7.30 NodeLocalName
     * 6.7.31 FullTextSearchScore
     * 6.7.32 LowerCase
     * 6.7.33 UpperCase
     * Parse an SQL2 dynamic operand
     *
     * @return \PHPCR\Query\QOM\DynamicOperandInterface
     */
    protected function parseDynamicOperand()
    {
        $token = $this->scanner->lookupNextToken();

        if ($this->scanner->tokenIs($token, 'LENGTH')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');
            $val = $this->parsePropertyValue();
            $this->scanner->expectToken(')');

            return $this->factory->length($val);
        }

        if ($this->scanner->tokenIs($token, 'NAME')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');

            $token = $this->scanner->fetchNextToken();
            if ($this->scanner->tokenIs($token, ')')) {
                return $this->factory->nodeName();
            }

            $this->scanner->expectToken(')');

            return $this->factory->nodeName($token);
        }

        if ($this->scanner->tokenIs($token, 'LOCALNAME')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');

            $token = $this->scanner->fetchNextToken();
            if ($this->scanner->tokenIs($token, ')')) {
                return $this->factory->nodeLocalName();
            }

            $this->scanner->expectToken(')');

            return $this->factory->nodeLocalName($token);
        }

        if ($this->scanner->tokenIs($token, 'SCORE')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');

            $token = $this->scanner->fetchNextToken();
            if ($this->scanner->tokenIs($token, ')')) {
                return $this->factory->fullTextSearchScore();
            }

            $this->scanner->expectToken(')');

            return $this->factory->fullTextSearchScore($token);
        }

        if ($this->scanner->tokenIs($token, 'LOWER')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');
            $op = $this->parseDynamicOperand();
            $this->scanner->expectToken(')');

            return $this->factory->lowerCase($op);
        }

        if ($this->scanner->tokenIs($token, 'UPPER')) {
            $this->scanner->fetchNextToken();
            $this->scanner->expectToken('(');
            $op = $this->parseDynamicOperand();
            $this->scanner->expectToken(')');

            return $this->factory->upperCase($op);
        }

        return $this->parsePropertyValue();
    }

    /**
     * 6.7.27 PropertyValue
     * Parse an SQL2 property value
     *
     * @return \PHPCR\Query\QOM\PropertyValueInterface
     */
    protected function parsePropertyValue()
    {
        list($prop, $selector) = $this->parseIdentifier();

        return $this->factory->propertyValue($prop, $selector);
    }

    /**
     * 6.7.34 Literal
     * Parse an SQL2 literal value
     *
     * @return \PHPCR\Query\QOM\LiteralInterface
     */
    protected function parseLiteral()
    {
        $token = $this->scanner->fetchNextToken();

        $quoteString = false;
        if (substr($token, 0, 1) === '\'') {
            $quoteString = "'";
        } elseif (substr($token, 0, 1) === '"') {
            $quoteString = '"';
        }

        if ($quoteString) {
            while (substr($token, -1) !== $quoteString) {
                $nextToken = $this->scanner->fetchNextToken();
                if ('' === $nextToken) {
                    break;
                }
                $token .= $nextToken;
            }

            if (substr($token, -1) !== $quoteString) {
                throw new \Exception("Syntax error: unterminated quoted string $token in '{$this->sql2}'");
            }

            $token = substr($token, 1, -1);
        }

        return $this->factory->literal($token);
    }

    /**
     * 6.7.37 Ordering
     */
    protected function parseOrderings()
    {
        $orderings = array();
        $continue = true;
        while ($continue) {
            $orderings[] = $this->parseOrdering();
            if ($this->scanner->tokenIs($this->scanner->lookupNextToken(), ',')) {
                $this->scanner->expectToken(',');
            } else {
                $continue = false;
            }
        }

        return $orderings;
    }

    /**
     * 6.7.38 Order
     */
    protected function parseOrdering()
    {
        $operand = $this->parseDynamicOperand();
        $token = strtoupper($this->scanner->lookupNextToken());

        if ($token === 'DESC') {
            $this->scanner->expectToken('DESC');

            return $this->factory->descending($operand);
        }

        if ($token === 'ASC' || $token === ',' || $token === '') {
            if ($token === 'ASC') {
                // Consume ASC
                $this->scanner->fetchNextToken();
            }

            return $this->factory->ascending($operand);
        }

        throw new \Exception("Syntax error: invalid ordering in '{$this->sql2}'");
    }

    /**
     * 6.7.39 Column
     * Parse an SQL2 columns definition and return an array of QOM\Column
     *
     * @return array of array
     */
    protected function parseColumns()
    {
        $this->scanner->expectToken('SELECT');

        // Wildcard
        if ($this->scanner->lookupNextToken() === '*') {
            $this->scanner->fetchNextToken();

            return array();
        }

        $columns = array();
        $hasNext = true;

        // Column list
        while ($hasNext) {
            $columns[] = $this->parseColumn();

            // Are there more columns?
            if ($this->scanner->lookupNextToken() !== ',') {
                $hasNext = false;
            } else {
                $this->scanner->fetchNextToken();
            }

        }

        return $columns;
    }

    /**
     * Get the next token and make sure to remove the brackets if the token is
     * in the [ns:name] notation
     *
     * @return string
     */
    private function fetchTokenWithoutBrackets()
    {
        $token = $this->scanner->fetchNextToken();

        if (substr($token, 0, 1) === '[' && substr($token, -1) === ']') {
            // Remove brackets around the selector name
            $token = substr($token, 1, -1);
        }

        return $token;
    }

    /**
     * Parse something that is expected to be a property identifier.
     *
     * @return array with property name and selector name if specified, null otherwise
     */
    private function parseIdentifier()
    {
        $token = $this->fetchTokenWithoutBrackets();

        // selector.property
        if ($this->scanner->lookupNextToken() === '.') {
            $selectorName = $token;
            $this->scanner->fetchNextToken();
            $propertyName = $this->fetchTokenWithoutBrackets();
        } else {
            $selectorName = null;
            $propertyName = $token;
        }

        return array($propertyName, $selectorName);
    }

    /**
     * Parse a single SQL2 column definition and return a QOM\Column
     *
     * @return \PHPCR\Query\QOM\ColumnInterface
     */
    protected function parseColumn()
    {
        list($propertyName, $selectorName) = $this->parseIdentifier();

        // AS name
        if (strtoupper($this->scanner->lookupNextToken()) === 'AS') {
            $this->scanner->fetchNextToken();
            $columnName = $this->scanner->fetchNextToken();
        } else {
            $columnName = null;
        }

        return $this->factory->column($propertyName, $columnName, $selectorName);
    }
}
