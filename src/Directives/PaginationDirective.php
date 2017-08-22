<?php

namespace Bolt\Extension\SerWeb\Rest\Directives;

use Bolt\Storage\Query\QueryInterface;

/**
 *  Directive to add a limit modifier to the query.
 */
class PaginationDirective
{
    /**
     * @param QueryInterface $query
     * @param int            $limit
     */
    public function __invoke(QueryInterface $query, $pagination)
    {
        
        // Not implemented yet
        $offset = ($pagination->page - 1) * $pagination->limit;
        $query->getQueryBuilder()->setFirstResult($offset);
        $query->getQueryBuilder()->setMaxResults($pagination->limit);
        
        // counter
        $pagination->count = function () use ($query) {
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
