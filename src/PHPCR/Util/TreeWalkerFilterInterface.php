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

namespace PHPCR\Util;

use PHPCR\ItemInterface;

/**
 *
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 */
interface TreeWalkerFilterInterface
{
    /**
     * Whether to visit the passed item
     *
     * @param \PHPCR\ItemInterface $item
     *
     * @return mixed
     */
    public function mustVisit(ItemInterface $item);
}
