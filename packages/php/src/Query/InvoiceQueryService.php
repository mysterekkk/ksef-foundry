<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Query;

use LuroWeb\KsefFoundry\Auth\AuthorizedClient;
use LuroWeb\KsefFoundry\Exception\ConfigurationException;
use LuroWeb\KsefFoundry\Exception\KsefException;
use LuroWeb\KsefFoundry\Http\HttpRequest;
use LuroWeb\KsefFoundry\Support\Json;

/**
 * POST /invoices/query/metadata (a read-only POST, hence safe to retry like a GET) and a lazy pagination helper
 * implementing the documented hasMore / isTruncated protocol.
 */
final class InvoiceQueryService
{
    private const MAX_PAGES_PER_WINDOW = 100_000;

    public function __construct(private readonly AuthorizedClient $api)
    {
    }

    /** One page. pageOffset is the page index (0 = first), pageSize 10..250. */
    public function queryMetadata(
        InvoiceQueryFilters $filters,
        int $pageOffset = 0,
        int $pageSize = 100,
        SortOrder $sortOrder = SortOrder::Asc,
    ): InvoiceMetadataPage {
        $filters->assertValidRange();
        if ($pageSize < 10 || $pageSize > 250) {
            throw new ConfigurationException('pageSize must be 10..250.');
        }
        $r = $this->api->send(new HttpRequest(
            'POST',
            '/invoices/query/metadata',
            query: ['sortOrder' => $sortOrder->value, 'pageOffset' => $pageOffset, 'pageSize' => $pageSize],
            body: Json::encode($filters->toArray()),
            idempotent: true,
        ));

        return InvoiceMetadataPage::fromArray($r->json());
    }

    /**
     * Lazily yields every matching invoice. Ranges longer than 100 days are split into windows; inside a
     * window, hasMore advances the page and isTruncated (10 000 record cap) narrows the date range from the last
     * record and restarts at page 0 (duplicates at the boundary are dropped by KSeF number).
     *
     * @return \Generator<int, InvoiceMetadata>
     */
    public function iterate(InvoiceQueryFilters $filters, int $pageSize = 100, SortOrder $sortOrder = SortOrder::Asc): \Generator
    {
        /** @var array<string, int> $seen KSeF number => timestamp, pruned to the window boundary */
        $seen = [];
        foreach ($this->windows($filters, $sortOrder) as $window) {
            yield from $this->iterateWindow($window, $pageSize, $sortOrder, $seen);
            $boundary = $sortOrder === SortOrder::Asc
                ? ($window->to ?? new \DateTimeImmutable())->getTimestamp()
                : $window->from->getTimestamp();
            $seen = array_filter(
                $seen,
                static fn (int $ts): bool => $sortOrder === SortOrder::Asc ? $ts >= $boundary : $ts <= $boundary
            );
        }
    }

    /**
     * @param array<string, int> $seen
     * @return \Generator<int, InvoiceMetadata>
     */
    private function iterateWindow(InvoiceQueryFilters $filters, int $pageSize, SortOrder $sortOrder, array &$seen): \Generator
    {
        $current = $filters;
        $offset = 0;
        $pages = 0;
        while (true) {
            if (++$pages > self::MAX_PAGES_PER_WINDOW) {
                throw new KsefException(sprintf('Pagination aborted: more than %d pages.', self::MAX_PAGES_PER_WINDOW));
            }
            $page = $this->queryMetadata($current, $offset, $pageSize, $sortOrder);
            $fresh = 0;
            foreach ($page->invoices as $inv) {
                if (isset($seen[$inv->ksefNumber])) {
                    continue;
                }
                $seen[$inv->ksefNumber] = $inv->dateFor($filters->dateType)->getTimestamp();
                ++$fresh;
                yield $inv;
            }
            if (!$page->hasMore || $page->invoices === []) {
                return;
            }
            if ($fresh === 0) {
                // hasMore but nothing new: the server is repeating itself; never loop on it
                throw new KsefException('Pagination made no progress (hasMore with no new invoices); narrow the filters.');
            }
            if (!$page->isTruncated) {
                ++$offset;
                continue;
            }
            $last = $page->invoices[array_key_last($page->invoices)]->dateFor($filters->dateType);
            $current = $sortOrder === SortOrder::Asc
                ? $current->withRange($last, $current->to)
                : $current->withRange($current->from, $last);
            $offset = 0;
        }
    }

    /**
     * @return list<InvoiceQueryFilters>
     */
    private function windows(InvoiceQueryFilters $filters, SortOrder $sortOrder): array
    {
        $from = \DateTimeImmutable::createFromInterface($filters->from);
        $to = \DateTimeImmutable::createFromInterface($filters->to ?? new \DateTimeImmutable());
        if ($to < $from) {
            throw new ConfigurationException('dateRange.to must not be earlier than dateRange.from.');
        }
        $step = new \DateInterval('P' . (InvoiceQueryFilters::MAX_RANGE_DAYS - 1) . 'D');
        $out = [];
        $start = $from;
        while ($start < $to) {
            $end = $start->add($step);
            if ($end >= $to) {
                $out[] = $filters->withRange($start, $filters->to === null && count($out) === 0 ? null : $to);
                break;
            }
            $out[] = $filters->withRange($start, $end);
            $start = $end;
        }
        if ($out === []) {
            $out[] = $filters;
        }

        return $sortOrder === SortOrder::Desc ? array_reverse($out) : $out;
    }
}
