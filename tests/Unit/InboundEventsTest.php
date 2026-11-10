<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Inbound\InboundEvents;
use SkladX\WooCommerce\Inbound\ReceiptStore;

final class InboundEventsTest extends TestCase {

	private ReceiptStore $store;

	/** @var list<array{string, string|null}> Notified (status, previous status). */
	private array $notified = array();

	protected function setUp(): void {
		$this->store = new class() implements ReceiptStore {
			/** @var array<string, array<string, mixed>> */
			public array $rows = array();

			public function get( string $id ): ?array {
				return $this->rows[ $id ] ?? null;
			}

			public function put( string $id, array $summary ): void {
				$this->rows[ $id ] = $summary;
			}

			public function all(): array {
				return $this->rows;
			}
		};
		$this->notified = array();
	}

	private function events(): InboundEvents {
		return new InboundEvents(
			$this->store,
			function ( array $receipt, ?array $previous ): void {
				$this->notified[] = array( $receipt['status'], $previous['status'] ?? null );
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function receipt( string $status, string $updated_at, ?int $received = null ): array {
		return array(
			'id'         => 'inr_01',
			'number'     => 'PR-7',
			'name'       => 'Jeseň',
			'status'     => $status,
			'items'      => array( array( 'sku' => 'A', 'quantity_expected' => 10, 'quantity_received' => $received ) ),
			'updated_at' => $updated_at,
		);
	}

	public function test_webhook_updates_the_cache_and_notifies_once_per_status(): void {
		$events = $this->events();

		$this->assertSame( 'processed', $events->handle_event( 'inbound.announced', array( 'receipt' => $this->receipt( 'announced', '2026-11-10T10:00:00Z' ) ) ) );
		$this->assertSame( 'ignored', $events->handle_event( 'inbound.announced', array( 'receipt' => $this->receipt( 'announced', '2026-11-10T10:00:00Z' ) ) ) );
		$this->assertSame( 'processed', $events->handle_event( 'inbound.received', array( 'receipt' => $this->receipt( 'received', '2026-11-12T08:00:00Z', 8 ) ) ) );

		$this->assertSame( array( array( 'announced', null ), array( 'received', 'announced' ) ), $this->notified );

		$cached = $this->store->get( 'inr_01' );

		$this->assertSame( 'received', $cached['status'] );
		$this->assertSame( 8, $cached['received'] );
		$this->assertTrue( $cached['has_differences'] );
	}

	public function test_stale_events_are_ignored(): void {
		$events = $this->events();

		$events->handle_event( 'inbound.received', array( 'receipt' => $this->receipt( 'received', '2026-11-12T08:00:00Z', 10 ) ) );
		$result = $events->handle_event( 'inbound.arrived', array( 'receipt' => $this->receipt( 'arrived', '2026-11-11T08:00:00Z' ) ) );

		$this->assertSame( 'ignored', $result );
		$this->assertSame( 'received', $this->store->get( 'inr_01' )['status'] );
		$this->assertCount( 1, $this->notified );
	}

	public function test_refresh_does_not_announce_unknown_receipts_but_notices_changes(): void {
		$events = $this->events();

		$this->assertSame( 'processed', $events->apply( \SkladX\WooCommerce\Inbound\Receipt::normalize( $this->receipt( 'announced', '2026-11-10T10:00:00Z' ) ) ) );
		$this->assertSame( array(), $this->notified );

		$events->apply( \SkladX\WooCommerce\Inbound\Receipt::normalize( $this->receipt( 'receiving', '2026-11-11T10:00:00Z' ) ) );

		$this->assertSame( array( array( 'receiving', 'announced' ) ), $this->notified );
	}

	public function test_admin_changes_are_cached_without_notice(): void {
		$events = $this->events();

		$events->apply( \SkladX\WooCommerce\Inbound\Receipt::normalize( $this->receipt( 'draft', '2026-11-10T10:00:00Z' ) ), InboundEvents::SOURCE_ADMIN );
		$events->apply( \SkladX\WooCommerce\Inbound\Receipt::normalize( $this->receipt( 'announced', '2026-11-10T11:00:00Z' ) ), InboundEvents::SOURCE_ADMIN );

		$this->assertSame( 'announced', $this->store->get( 'inr_01' )['status'] );
		$this->assertSame( array(), $this->notified );

		$events->handle_event( 'inbound.announced', array( 'receipt' => $this->receipt( 'announced', '2026-11-10T11:00:00Z' ) ) );

		$this->assertSame( array(), $this->notified );
	}

	public function test_ignores_other_and_malformed_events(): void {
		$events = $this->events();

		$this->assertSame( 'ignored', $events->handle_event( 'order.shipped', array( 'receipt' => $this->receipt( 'announced', '' ) ) ) );
		$this->assertSame( 'ignored', $events->handle_event( 'inbound.announced', array( 'receipt' => array( 'number' => 'x' ) ) ) );
		$this->assertSame( 'ignored', $events->handle_event( 'inbound.announced', array() ) );
		$this->assertSame( array(), $this->store->all() );
		$this->assertTrue( InboundEvents::handles( 'inbound.cancelled' ) );
		$this->assertFalse( InboundEvents::handles( 'order.cancelled' ) );
	}
}
