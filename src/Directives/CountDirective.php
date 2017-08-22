<?php

namespace Bolt\Extension\SerWeb\Rest\Directives;

use Bolt\Storage\Query\QueryInterface;

/**
 *  Directive to add a limit modifier to the query.
 */
class CountDirective
{
    /**
     * @param QueryInterface $query
     * @param int            $related
     */
    public function __invoke(QueryInterface $query, $count)
    {
        $count->get = function () use ($query) {
            $queryCount = clone $query->getQueryBuilder();
            $queryCount
                ->resetQueryParts(['maxResults', 'firstResult', 'orderBy'])
                ->setFirstResult(null)
                ->setMaxResults(null)
                ->select('COUNT(*) as total');
            return $queryCount->execute()->rowCount();
        };
    }
}
