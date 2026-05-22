<?php

namespace Hashtopolis\dba;

abstract class Filter {
  /**
   * @param AbstractModelFactory $factory factory of the table where the column is contained from to filter on.
   * @param bool $includeTable include the table in the selected column list (required for avoiding ambigous errors on joins)
   * @return string
   */
  abstract function getQueryString(AbstractModelFactory $factory, bool $includeTable = false): string;
  
  /**
   * @return mixed if the filter is using a value to filter with, it is returned, null otherwise
   */
  abstract function getValue(): mixed;
  
  /**
   * @return bool if the filter itself is associated with a value or not
   */
  abstract function getHasValue(): bool;
}