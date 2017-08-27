<?php

namespace Bolt\Extension\SerWeb\Rest\Directives;

use Bolt\Storage\Query\QueryInterface;

/**
 *  Directive to add a limit modifier to the query.
 */
class FilterDirective
{
    /**
     * @param QueryInterface $query
     * @param StdClass       $unrelated
     */
    public function __invoke(QueryInterface $query, $search)
    {
        $qb = $query->getQueryBuilder();
        $orX = $qb->expr()->orX();
        $term = "'%" . $search->term . "%'";
        foreach ($search->fields as $field) {
            $col = $search->alias.".".$field;
            $orX->add($qb->expr()->like($col, $term));
        }

        $qb->andWhere($orX);
    }
}
