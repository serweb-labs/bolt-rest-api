<?php

namespace Bolt\Extension\SerWeb\Rest\Directives;

use Bolt\Storage\Query\QueryInterface;

/**
 *  Directive to add a limit modifier to the query.
 */
class RelatedDirective
{
    /**
     * @param QueryInterface $query
     * @param int            $related
     */
    public function __invoke(QueryInterface $query, $relatedRaw)
    {
        $related = explode(":", $relatedRaw);
        $to_ct = $related[0];
        $to_ids = $related[1];
        $qb = $query->getQueryBuilder();
        
        if (count($to_ct) > 0 && empty(!$to_ids)) {
            $qb->andWhere($to_ct.".to_id IN(" . $to_ids . ")");
        } elseif (count($to_ct) > 0) {
            $qb->having("COUNT(" . $to_ct . ".id) > 0");
        }
    }
}
