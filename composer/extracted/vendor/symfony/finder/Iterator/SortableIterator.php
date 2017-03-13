<?php










namespace Symfony\Component\Finder\Iterator;






class SortableIterator implements \IteratorAggregate
{
const SORT_BY_NAME = 1;
const SORT_BY_TYPE = 2;
const SORT_BY_ACCESSED_TIME = 3;
const SORT_BY_CHANGED_TIME = 4;
const SORT_BY_MODIFIED_TIME = 5;

private $iterator;
private $sort;









public function __construct(\Traversable $iterator, $sort)
{
$this->iterator = $iterator;

if (self::SORT_BY_NAME === $sort) {
$this->sort = function ($a, $b) {
return strcmp($a->getRealpath() ?: $a->getPathname(), $b->getRealpath() ?: $b->getPathname());
};
} elseif (self::SORT_BY_TYPE === $sort) {
$this->sort = function ($a, $b) {
if ($a->isDir() && $b->isFile()) {
return -1;
} elseif ($a->isFile() && $b->isDir()) {
return 1;
}

return strcmp($a->getRealpath() ?: $a->getPathname(), $b->getRealpath() ?: $b->getPathname());
};
} elseif (self::SORT_BY_ACCESSED_TIME === $sort) {
$this->sort = function ($a, $b) {
return $a->getATime() - $b->getATime();
};
} elseif (self::SORT_BY_CHANGED_TIME === $sort) {
$this->sort = function ($a, $b) {
return $a->getCTime() - $b->getCTime();
};
} elseif (self::SORT_BY_MODIFIED_TIME === $sort) {
$this->sort = function ($a, $b) {
return $a->getMTime() - $b->getMTime();
};
} elseif (is_callable($sort)) {
$this->sort = $sort;
} else {
throw new \InvalidArgumentException('The SortableIterator takes a PHP callable or a valid built-in sort algorithm as an argument.');
}
}

public function getIterator()
{
$array = iterator_to_array($this->iterator, true);
uasort($array, $this->sort);

return new \ArrayIterator($array);
}
}