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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 2, backoffMs: [0]);
        $out = $chain->issueWithFallback('ord_x', 'SKU');

        $this->assertSame('primary', $out['supplier']);
        $this->assertFalse($fallbackCalled);
    }

    public function test_recovers_lie_error_via_issuance_lookup(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('supplier_error');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return 'LIE-RECOVERED-001';
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('SHOULD-NOT');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 1, backoffMs: [0]);
        $out = $chain->issueWithFallback('ord_lie', 'KEY-GTA5');

        $this->assertTrue($out['result']->success);
        $this->assertSame('LIE-RECOVERED-001', $out['result']->code);
        $this->assertSame('primary', $out['supplier']);
        $this->assertSame('req_ord_lie-1', $out['request_id']);
    }

    public function test_ambiguous_supplier_error_without_issuance_does_not_failover(): void
    {
        $fallbackCalled = false;

        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('supplier_error');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
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

                return SupplierIssueResultDto::ok('SHOULD-NOT');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 1, backoffMs: [0]);
        $out = $chain->issueForItem('ord_amb', 'KEY-GTA5', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'primary');

        $this->assertFalse($out['result']->success);
        $this->assertSame('supplier_error', $out['result']->reason);
        $this->assertSame('primary', $out['supplier']);
        $this->assertFalse($fallbackCalled);
    }

    public function test_issue_for_item_uses_fallback_as_preferred(): void
    {
        $primary = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'primary';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::error('should_not');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $fallback = new class implements SupplierClientInterface {
            public function name(): string
            {
                return 'fallback';
            }

            public function issue(SupplierIssueRequestDto $request): SupplierIssueResultDto
            {
                return SupplierIssueResultDto::ok('FROM-PREFERRED-FALLBACK');
            }

            public function findIssuedCode(string $requestId): ?string
            {
                return null;
            }
        };

        $chain = new SupplierChain($primary, $fallback, maxRetries: 1, backoffMs: [0]);
        $itemId = '11111111-2222-3333-4444-555555555555';
        $out = $chain->issueForItem('ord_pref', 'KEY-CS2-PRIME', $itemId, 'fallback');

        $this->assertTrue($out['result']->success);
        $this->assertSame('fallback', $out['supplier']);
        $this->assertSame('req_ord_pref_'.str_replace('-', '', $itemId).'-1', $out['request_id']);
    }
}
