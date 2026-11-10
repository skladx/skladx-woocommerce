<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Inbound\BulkParser;
use SkladX\WooCommerce\Inbound\ValidationException;

final class BulkParserTest extends TestCase {

	public function test_parses_all_line_formats(): void {
		$text = "RF-TRIKO-M;24\n"
			. "8588000000062;10;L2026-11\n"
			. "RF-FLASA;5;B7;31.12.2027\n"
			. "RF-HRNCEK;3;;2028-03\n";

		$this->assertSame(
			array(
				array( 'line' => 1, 'code' => 'RF-TRIKO-M', 'quantity' => 24, 'batch' => '', 'expires_at' => '' ),
				array( 'line' => 2, 'code' => '8588000000062', 'quantity' => 10, 'batch' => 'L2026-11', 'expires_at' => '' ),
				array( 'line' => 3, 'code' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '2027-12-31' ),
				array( 'line' => 4, 'code' => 'RF-HRNCEK', 'quantity' => 3, 'batch' => '', 'expires_at' => '2028-03-31' ),
			),
			BulkParser::parse( $text )['rows']
		);
	}

	public function test_accepts_tabs_commas_spaces_header_comments_and_windows_line_endings(): void {
		$text = "\xEF\xBB\xBFSKU\tPočet\tŠarža\tExpirácia\r\n"
			. "# poznámka\r\n"
			. "\r\n"
			. "A\t2\tL1\t2027-01-31\r\n"
			. "B,3\r\n"
			. "C 4\r\n"
			. "\"D\";\"5\";\r\n";

		$result = BulkParser::parse( $text );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array( array( 4, 'A', 2, 'L1', '2027-01-31' ), array( 5, 'B', 3, '', '' ), array( 6, 'C', 4, '', '' ), array( 7, 'D', 5, '', '' ) ),
			array_map( static fn ( array $row ): array => array_values( $row ), $result['rows'] )
		);
	}

	public function test_header_is_only_skipped_on_the_first_line(): void {
		$result = BulkParser::parse( "A;1\nSKU;počet\n" );

		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( array( array( 2, 'quantity', ValidationException::INVALID_QUANTITY ) ), $this->summary( $result['errors'] ) );
	}

	public function test_reports_errors_with_line_numbers_and_keeps_valid_lines(): void {
		$text = "A;1\n"
			. "B;0\n"
			. "C;x\n"
			. "D\n"
			. ";4\n"
			. "E;2;" . str_repeat( 'L', 65 ) . "\n"
			. "F;2;L1;31.02.2027\n"
			. "G;1;L1;2027-01-01;extra\n"
			. "H;-2\n"
			. "I;1,5\n"
			. "J;7\n";

		$result = BulkParser::parse( $text );

		$this->assertSame( array( 'A', 'J' ), array_column( $result['rows'], 'code' ) );
		$this->assertSame( array( 1, 11 ), array_column( $result['rows'], 'line' ) );
		$this->assertSame(
			array(
				array( 2, 'quantity', ValidationException::QUANTITY_POSITIVE ),
				array( 3, 'quantity', ValidationException::INVALID_QUANTITY ),
				array( 4, 'quantity', ValidationException::REQUIRED ),
				array( 5, 'code', ValidationException::REQUIRED ),
				array( 6, 'batch', ValidationException::TOO_LONG ),
				array( 7, 'expires_at', ValidationException::INVALID_DATE ),
				array( 8, 'line', ValidationException::TOO_MANY_COLUMNS ),
				array( 9, 'quantity', ValidationException::QUANTITY_POSITIVE ),
				array( 10, 'quantity', ValidationException::INVALID_QUANTITY ),
			),
			$this->summary( $result['errors'] )
		);

		$messages = ( new ValidationException( $result['errors'] ) )->localized();

		$this->assertSame( 'Line 2: Quantity must be greater than 0.', $messages[0]['message'] );
		$this->assertSame( 'Line 3: Quantity "x" is not a whole number.', $messages[1]['message'] );
		$this->assertSame( 'Line 7: Expiry "31.02.2027" is not a valid date (use YYYY-MM-DD or DD.MM.YYYY).', $messages[5]['message'] );
	}

	public function test_same_code_with_other_batches_stays_separate(): void {
		$rows = BulkParser::parse( "A;1;L1\nA;2;L2\nA;3;L1\n" )['rows'];

		$this->assertSame( array( 1, 2, 3 ), array_column( $rows, 'quantity' ) );
	}

	public function test_limits_the_number_of_lines(): void {
		$result = BulkParser::parse( str_repeat( "A;1\n", BulkParser::MAX_LINES + 5 ) );

		$this->assertCount( BulkParser::MAX_LINES, $result['rows'] );
		$this->assertSame( array( array( BulkParser::MAX_LINES + 1, 'lines', ValidationException::TOO_MANY_LINES ) ), $this->summary( $result['errors'] ) );
	}

	public function test_empty_input(): void {
		$this->assertSame( array( 'rows' => array(), 'errors' => array() ), BulkParser::parse( "\n  \n" ) );
	}

	/**
	 * @param list<array<string, mixed>> $errors Errors.
	 * @return list<array{int|null, string, string}>
	 */
	private function summary( array $errors ): array {
		return array_map( static fn ( array $error ): array => array( $error['line'], $error['field'], $error['reason'] ), $errors );
	}
}
