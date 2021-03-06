<?php

/*
 * This file is part of vaibhavpandeyvpz/doctrine-datatables package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.md.
 */

namespace Doctrine\DataTables;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\QueryBuilder as ORMQueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Class Builder
 * @package Doctrine\DataTables
 */
class Builder
{

    /**
     * @var array
     */
    protected $columnAliases = array();

    /**
     * @var string
     */
    protected $columnField = 'data'; // or 'name'

    /**
     * @var string
     */
    protected $indexColumn = '*';

    /**
     * @var bool
     */
    protected $returnCollection = false;

    /**
     * @var bool
     */
    protected $caseInsensitive = false;

    /**
     * @var QueryBuilder|ORMQueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var array
     */
    protected $requestParams;

    /**
     * @var string
     */
    protected $searchDelimiter = false;


    /**
     * @return array
     */
    public function getData()
    {
        $query = $this->getFilteredQuery();
        $columns = &$this->requestParams['columns'];
        // Order
        if (array_key_exists('order', $this->requestParams)) {
            $order = &$this->requestParams['order'];
            foreach ($order as $sort) {
                $column = &$columns[intval($sort['column'])];
                if (array_key_exists($column[$this->columnField], $this->columnAliases)) {
                    $column[$this->columnField] = $this->columnAliases[$column[$this->columnField]];
                }
                $query->addOrderBy($column[$this->columnField], $sort['dir']);
            }
        }
        // Offset
        if (array_key_exists('start', $this->requestParams)) {
            $query->setFirstResult(intval($this->requestParams['start']));
        }
        // Limit
        if (array_key_exists('length', $this->requestParams)) {
            $length = intval($this->requestParams['length']);
            if ($length > 0) {
                $query->setMaxResults($length);
            }
        }
        // Fetch
        return $query instanceof ORMQueryBuilder ?
            $query->getQuery()->getScalarResult() : $query->execute()->fetchAll();
    }

    /**
     * @return QueryBuilder|ORMQueryBuilder
     */
    public function getFilteredQuery()
    {
        $query = clone $this->queryBuilder;
        $columns = &$this->requestParams['columns'];
        $c = (is_array($columns) ? count($columns) : 0);
        // Search
        if (array_key_exists('search', $this->requestParams)) {
            if ($value = trim($this->requestParams['search']['value'])) {

                if ($this->searchDelimiter !== false && strpos($value, $this->searchDelimiter) !== false) {
                    $index = 0;
                    $parts = explode($this->searchDelimiter, $value);
                    $partsAndX = $query->expr()->andX();
                    foreach ($parts as $part) {

                        $orX = $query->expr()->orX();
                        for ($i = 0; $i < $c; $i++) {
                            $column = &$columns[$i];
                            if ($column['searchable'] == 'true') {
                                if (array_key_exists($column[$this->columnField], $this->columnAliases)) {
                                    $column[$this->columnField] = $this->columnAliases[$column[$this->columnField]];
                                }
                                $orX->add($query->expr()->like($column[$this->columnField], ':search' . $index));
                            }
                        }

                        $partsAndX->add($orX);
                        $query->setParameter('search' . $index, "%{$part}%");
                        $index++;
                    }
                    if ($partsAndX->count() >= 1) {
                        $query->andWhere($partsAndX);
                    }
                } else {
                    $orX = $query->expr()->orX();
                    for ($i = 0; $i < $c; $i++) {
                        $column = &$columns[$i];
                        if ($column['searchable'] == 'true') {
                            if (array_key_exists($column[$this->columnField], $this->columnAliases)) {
                                $column[$this->columnField] = $this->columnAliases[$column[$this->columnField]];
                            }
                            $orX->add($query->expr()->like($column[$this->columnField], ':search'));
                        }
                    }
                    if ($orX->count() >= 1) {
                        $query->andWhere($orX)
                            ->setParameter('search', "%{$value}%");
                    }
                }
            }
        }
        // Filter

        for ($i = 0; $i < $c; $i++) {
            $column = &$columns[$i];
            $andX = $query->expr()->andX();
            $between = false;
            if (($column['searchable'] == 'true') && ($value = trim($column['search']['value']))) {
                if (array_key_exists($column[$this->columnField], $this->columnAliases)) {
                    $column[$this->columnField] = $this->columnAliases[$column[$this->columnField]];
                }
                $operator = preg_match('~^\[(?<operator>[|=!%<>:]+)\].*$~', $value, $matches) ? $matches['operator'] : '=';
                if ($this->caseInsensitive) {
                    $searchColumn = "lower(" . $column[$this->columnField] . ")";
                    $filter = "lower(:filter_{$i})";
                    $filterY = "lower(:filterY_{$i})";
                } else {
                    $searchColumn = $column[$this->columnField];
                    $filter = ":filter_{$i}";
                    $filterY = ":filterY_{$i}";
                }
                switch ($operator) {
                    case '!=': // Not equals; usage: [!=]search_term
                        $andX->add($query->expr()->neq($searchColumn, $filter));
                        break;
                    case '%': // Like; usage: [%]search_term
                        $andX->add($query->expr()->like($searchColumn, $filter));
                        $value = "%{$value}%";
                        break;
                    case '<': // Less than; usage: [<]search_term
                        $andX->add($query->expr()->lt($searchColumn, $filter));
                        break;
                    case '>': // Greater than; usage: [>]search_term
                        $andX->add($query->expr()->gt($searchColumn, $filter));
                        break;
                    case '<=': // Less than or equal; usage: [<=]search_term
                        $andX->add($query->expr()->lte($searchColumn, $filter));
                        break;
                    case '>=': // Greater than or equal; usage: [>=]search_term
                        $andX->add($query->expr()->gte($searchColumn, $filter));
                        break;
                    case '=': // Equals (default); usage: [=]search_term
                    default:
                        $andX->add($query->expr()->eq($searchColumn, $filter));
                        break;
                    case '|': // Equals for multiple values; usage: [|]search_term
                        $value = explode('[|]', $value);
                        $andX->add($query->expr()->in($searchColumn, $filter));
                        break;
                    case ':': // Between a range; usage: [:]search_term
                        $value = explode('[:]', $value);
                        $andX->add($query->expr()->between($searchColumn, $filter, $filterY));
                        $between = true;
                        break;
                }
                if ($between) {
                    $query->setParameter("filter_{$i}", $value[0]);
                    $query->setParameter("filterY_{$i}", $value[1]);
                } else {
                    $query->setParameter("filter_{$i}", $value);
                }
            }
            if ($andX->count() >= 1) {
                $query->andWhere($andX);
            }
        }
        // Done
        return $query;
    }

    /**
     * @return int
     */
    public function getRecordsFiltered()
    {
        $query = $this->getFilteredQuery();
        if ($query instanceof ORMQueryBuilder) {
            return $query->resetDQLPart('select')
                ->select("COUNT({$this->indexColumn})")
                ->getQuery()
                ->getSingleScalarResult();
        } else {
            return $query->resetQueryPart('select')
                ->select("COUNT({$this->indexColumn})")
                ->execute()
                ->fetchColumn(0);
        }
    }

    /**
     * @return int
     */
    public function getRecordsTotal()
    {
        $query = clone $this->queryBuilder;
        if ($query instanceof ORMQueryBuilder) {
            return $query->resetDQLPart('select')
                ->select("COUNT({$this->indexColumn})")
                ->getQuery()
                ->getSingleScalarResult();
        } else {
            return $query->resetQueryPart('select')
                ->select("COUNT({$this->indexColumn})")
                ->execute()
                ->fetchColumn(0);
        }
    }

    /**
     * @return array
     */
    public function getResponse()
    {
        return array(
            'data' => $this->getData(),
            'draw' => $this->requestParams['draw'],
            'recordsFiltered' => $this->getRecordsFiltered(),
            'recordsTotal' => $this->getRecordsTotal(),
        );
    }

    /**
     * @param string $indexColumn
     * @return static
     */
    public function withIndexColumn($indexColumn)
    {
        $this->indexColumn = $indexColumn;
        return $this;
    }

    /**
     * @param array $columnAliases
     * @return static
     */
    public function withColumnAliases($columnAliases)
    {
        $this->columnAliases = $columnAliases;
        return $this;
    }

    /**
     * @param bool $returnObjectCollection
     * @return static
     */
    public function withReturnCollection($returnCollection)
    {
        $this->returnCollection = $returnCollection;
        return $this;
    }

    /**
     * @param bool $caseInsensitive
     * @return static
     */
    public function withCaseInsensitive($caseInsensitive)
    {
        $this->caseInsensitive = $caseInsensitive;
        return $this;
    }

    /**
     * @param string $columnField
     * @return static
     */
    public function withColumnField($columnField)
    {
        $this->columnField = $columnField;
        return $this;
    }

    /**
     * @param QueryBuilder|ORMQueryBuilder $queryBuilder
     * @return static
     */
    public function withQueryBuilder($queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    /**
     * @param array $requestParams
     * @return static
     */
    public function withRequestParams($requestParams)
    {
        $this->requestParams = $requestParams;
        return $this;
    }

    /**
     * @param string $delimiter
     * @return static
     */
    public function withSearchDelimiter($delimiter)
    {
        $this->searchDelimiter = $delimiter;
        return $this;
    }
}
