<?php

namespace Tests\Unit;

use App\Contracts\Supplier\SupplierClientInterface;
use App\DTO\Supplier\SupplierIssueRequestDto;
use App\DTO\Supplier\SupplierIssueResultDto;
use App\Services\Supplier\SupplierChain;
use Tests\TestCase;

class SupplierChainTest extends TestCase
{
    public function test_retries_same_request_id_after_timeout_then_succeeds(): void
    {
        $seen = [];

        $primary = new class($seen) implements SupplierClientInterface {
            private int $calls = 0;

            public function __construct(private array &$seen) {}

            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                $this->calls++;
                $this->seen[] = $request->requestId;

                if ($this->calls === 1) {
                    return SupplierIssueResultDto::timeout();
                }

                return SupplierIssueResultDto::ok('CODE-FROM-PRIMARY');
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('should_not_be_called');
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 3, backoffMs: [0, 0, 0]);
        $out = $chain->issueWithFallback('ord_test1', 'KEY-CS2-PRIME');

        $this->assertTrue($out['result']->success);
        $this->assertSame('primary', $out['supplier']);
        $this->assertSame('req_ord_test1-1', $out['request_id']);
        $this->assertSame(['req_ord_test1-1', 'req_ord_test1-1'], $seen);
    }

    public function test_falls_back_to_fallback_when_primary_definitively_fails(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('supplier_unavailable');
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('CODE-FROM-FALLBACK');
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 3, backoffMs: [0]);
        $out = $chain->issueWithFallback('ord_test2', 'KEY-GTA5');

        $this->assertTrue($out['result']->success);
        $this->assertSame('fallback', $out['supplier']);
        $this->assertSame('req_ord_test2-2', $out['request_id']);
    }

    public function test_does_not_call_fallback_when_primary_succeeds(): void
    {
        $fallbackCalled = false;

        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('OK');
            }
        };

        $fallback = new class($fallbackCalled) implements SupplierClientInterface {
            public function __construct(private bool &$fallbackCalled) {}

            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                $this->fallbackCalled = true;

                return SupplierIssueResultDto::ok('FALLBACK');
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 2, backoffMs: [0]);
        $out = $chain->issueWithFallback('ord_x', 'SKU');

        $this->assertSame('primary', $out['supplier']);
        $this->assertFalse($fallbackCalled);
    }
}
