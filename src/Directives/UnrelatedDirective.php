<?php

namespace Bolt\Extension\SerWeb\Rest\Directives;

use Bolt\Storage\Query\QueryInterface;

/**
 *  Directive to add a limit modifier to the query.
 */
class UnrelatedDirective
{
    /**
     * @param QueryInterface $query
     * @param int            $related
     */
    public function __invoke(QueryInterface $query, $unrelatedRaw)
    {
        $unrelated = explode(":", $unrelatedRaw);
        $to_ct = $unrelated[0];
        $to_ids = $unrelated[1];
        $qb = $query->getQueryBuilder();
        if (count($to_ct) > 0 && count($to_ids) > 0) {
            $qb->andWhere($to_ct.".to_id NOT IN(" . $to_ids . ")");
        } elseif (count($to_ct) > 0) {
            $qb->having("COUNT(" . $to_ct . ".id)=0");
        }
    }
}
