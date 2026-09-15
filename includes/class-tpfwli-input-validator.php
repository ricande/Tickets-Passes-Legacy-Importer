<?php
defined('ABSPATH') || exit;

/**
 * Customer/quantity validation. Product/stock/validity are re-fetched by the TPFW adapter.
 */
final class TPFWLI_Input_Validator
{
	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool,errors:string[],data:array<string,mixed>}
	 */
	public function validate_customer(array $input): array
	{
		$errors = array();

		$first = isset($input['first_name']) ? sanitize_text_field((string) $input['first_name']) : '';
		$last  = isset($input['last_name']) ? sanitize_text_field((string) $input['last_name']) : '';
		$email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
		$phone = isset($input['phone']) ? (string) $input['phone'] : '';
		$qty   = isset($input['quantity']) ? $input['quantity'] : '';
		$pid   = isset($input['product_id']) ? absint($input['product_id']) : 0;
		$iid   = isset($input['import_id']) ? sanitize_text_field((string) $input['import_id']) : '';

		if ($first === '') {
			$errors[] = __('First name is required.', 'tickets-passes-legacy-importer');
		}
		if ($last === '') {
			$errors[] = __('Last name is required.', 'tickets-passes-legacy-importer');
		}
		if ($email === '' || !is_email($email)) {
			$errors[] = __('A valid email address is required.', 'tickets-passes-legacy-importer');
		}
		if ($phone !== '') {
			$phone = function_exists('wc_sanitize_phone_number')
				? wc_sanitize_phone_number($phone)
				: sanitize_text_field($phone);
		}
		if ($phone === '') {
			$errors[] = __('Phone is required.', 'tickets-passes-legacy-importer');
		}

		if (!is_numeric($qty) || (string) (int) $qty !== (string) $qty || (int) $qty < 1) {
			$errors[] = __('Quantity must be a positive integer.', 'tickets-passes-legacy-importer');
			$qty = 0;
		} else {
			$qty = (int) $qty;
		}

		if ($pid < 1) {
			$errors[] = __('Choose a Ticket product.', 'tickets-passes-legacy-importer');
		}

		if ($iid === '' || !$this->is_uuid($iid)) {
			$errors[] = __('The import ID is missing or invalid.', 'tickets-passes-legacy-importer');
		}

		return array(
			'ok'     => $errors === array(),
			'errors' => $errors,
			'data'   => array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'phone'      => $phone,
				'quantity'   => $qty,
				'product_id' => $pid,
				'import_id'  => $iid,
			),
		);
	}

	public function is_uuid(string $value): bool
	{
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}

	public function new_import_id(): string
	{
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
