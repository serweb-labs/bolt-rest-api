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
        //dump(get_class_methods($query), $query->getContentType()); exit;
        $ct = $query->getContentType();
        $fields = ($search->getFields)($ct);
        $alias = "_" . $ct;
        $qb = $query->getQueryBuilder();
        $orX = $qb->expr()->orX();
        $term = "'%" . $search->term . "%'";
        foreach ($fields as $field) {
            $col = $alias.".".$field;
            $orX->add($qb->expr()->like($col, $term));
        }

        $qb->andWhere($orX);
    }
}
