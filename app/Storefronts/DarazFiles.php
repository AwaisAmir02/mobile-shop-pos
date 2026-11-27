<?php

namespace App\Storefronts;

use App\Imports\ProductSheetReader;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reads Daraz Seller Center files (O6): the order export (Orders › Manage
 * Orders › Export) and the account statement / transaction export (Finance).
 *
 * Daraz publishes no specification of these files, and its Seller Center is
 * Lazada's platform, whose exports use either Title Case headers ("Order
 * Number", "Seller SKU", "Paid Price") or the API's snake/camel case
 * ("order_number", "sellerSku", "paidPrice"). So columns are found by name,
 * from the aliases below, in any order, ignoring case, spaces and punctuation,
 * and the header row may sit below a title line. A file without a required
 * column is refused, naming the columns it did have, so a new layout shows
 * itself at once rather than being misread.
 *
 * The order export is one row per item (Lazada's is one row per piece), so
 * rows are grouped by order number and summed by SKU.
 */
class DarazFiles
{
    /** @var array<string, list<string>> */
    public const ORDER_COLUMNS = [
        'order_number' => ['Order Number', 'Order No.', 'Order No', 'Order ID', 'order_number', 'orderNumber', 'order_id'],
        'order_item_id' => ['Order Item Id', 'Order Item ID', 'order_item_id', 'orderItemId'],
        'seller_sku' => ['Seller SKU', 'SellerSku', 'seller_sku', 'sellerSku', 'SKU'],
        'item_name' => ['Item Name', 'Product Name', 'item_name', 'itemName', 'name'],
        'unit_price' => ['Unit Price', 'Item Price', 'unit_price', 'unitPrice', 'item_price'],
        'paid_price' => ['Paid Price', 'paid_price', 'paidPrice'],
        'quantity' => ['Quantity', 'Qty', 'quantity'],
        'status' => ['Status', 'Order Status', 'Item Status', 'status', 'order_status'],
        'created_at' => ['Created at', 'Created At', 'Order Date', 'created_at', 'createTime'],
        'customer_name' => ['Customer Name', 'Shipping Name', 'customer_name', 'customerName', 'shipping_name'],
        'phone' => ['Shipping Phone Number', 'Shipping Phone', 'Phone', 'Customer Phone', 'shipping_phone'],
        'address' => ['Shipping Address', 'Address', 'shipping_address', 'shippingAddress'],
        'city' => ['Shipping City', 'City', 'shipping_city', 'shippingCity'],
        'payment_method' => ['Payment Method', 'payment_method', 'paymentMethod'],
        'tracking' => ['Tracking Code', 'Tracking Number', 'tracking_code', 'trackingCode'],
    ];

    public const ORDER_REQUIRED = ['order_number', 'seller_sku', 'status'];

    /** @var array<string, list<string>> */
    public const STATEMENT_COLUMNS = [
        'date' => ['Transaction Date', 'Date', 'transaction_date', 'transactionDate'],
        'type' => ['Transaction Type', 'Type', 'transaction_type', 'transactionType'],
        'fee_name' => ['Fee Name', 'Fee Type', 'Details', 'Description', 'fee_name', 'feeName'],
        'amount' => ['Amount', 'Amount (PKR)', 'Amount(Include Tax)', 'amount'],
        'order_number' => ['Order No.', 'Order No', 'Order Number', 'order_no', 'orderNo', 'order_number'],
        'order_item_id' => ['Order Item No.', 'Order Item No', 'Order Item Id', 'orderItem_no', 'order_item_no'],
        'wht' => ['WHT Amount', 'WHT', 'Withholding Tax', 'WHT_amount', 'wht_amount'],
        'wht_included' => ['WHT included in Amount', 'WHT Included In Amount', 'wht_included_in_amount'],
        'vat' => ['VAT in Amount', 'VAT', 'vat_in_amount'],
        'statement' => ['Statement', 'Statement Number', 'statement'],
    ];

    public const STATEMENT_REQUIRED = ['amount'];

    /**
     * @return list<array<string, string>> one per data row, keyed by our column names
     *
     * @throws ValidationException
     */
    public static function orders(string $path, string $extension): array
    {
        return self::read($path, $extension, self::ORDER_COLUMNS, self::ORDER_REQUIRED, 'ordersFile');
    }

    /** @return list<array<string, string>> */
    public static function statement(string $path, string $extension): array
    {
        $rows = self::read($path, $extension, self::STATEMENT_COLUMNS, self::STATEMENT_REQUIRED, 'statementFile');

        if ($rows !== [] && ! array_key_exists('fee_name', $rows[0]) && ! array_key_exists('type', $rows[0])) {
            throw ValidationException::withMessages(['statementFile' => 'This statement has no Fee Name or Transaction Type column, so its lines can\'t be told apart.']);
        }

        return $rows;
    }

    /**
     * @param  array<string, list<string>>  $columns
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    protected static function read(string $path, string $extension, array $columns, array $required, string $errorKey): array
    {
        $type = match (strtolower($extension)) {
            'csv', 'txt' => ExcelType::CSV,
            'xls' => ExcelType::XLS,
            'xlsx' => ExcelType::XLSX,
            default => throw ValidationException::withMessages([$errorKey => 'Upload the file as Daraz exports it: .xlsx, .xls or .csv.']),
        };

        $sheet = Excel::toArray(new ProductSheetReader, $path, null, $type)[0] ?? [];
        $aliases = [];

        foreach ($columns as $key => $names) {
            foreach ($names as $name) {
                $aliases[self::slug($name)] = $key;
            }
        }

        // The header row: the first of the first ten that names every required column.
        foreach (array_slice($sheet, 0, 10, true) as $index => $row) {
            $map = [];

            foreach ($row as $col => $cell) {
                $key = $aliases[self::slug((string) $cell)] ?? null;

                if ($key !== null && ! in_array($key, $map, true)) {
                    $map[$col] = $key;
                }
            }

            if (array_diff($required, $map) === []) {
                return self::rows(array_slice($sheet, $index + 1), $map);
            }
        }

        $found = collect($sheet[0] ?? [])->filter(fn ($cell) => filled($cell))->map(fn ($cell) => (string) $cell)->take(15)->implode(', ');

        throw ValidationException::withMessages([$errorKey => 'This doesn\'t look like the Daraz file we need: it should have '
            .collect($required)->map(fn ($key) => '"'.$columns[$key][0].'"')->implode(', ').' columns.'
            .($found !== '' ? ' Its first row has: '.$found.'.' : '')]);
    }

    /** @return list<array<string, string>> */
    protected static function rows(array $data, array $map): array
    {
        $rows = [];

        foreach ($data as $raw) {
            $row = [];

            foreach ($map as $col => $key) {
                $row[$key] = trim((string) ($raw[$col] ?? ''));
            }

            if (collect($row)->filter(fn ($value) => $value !== '')->isNotEmpty()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function slug(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '';
    }

    /** "Rs 1,250.00", "-35.5", "(35.50)" → float. */
    public static function money(?string $value): float
    {
        $value = trim((string) $value);
        $negative = str_starts_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $number = (float) (preg_replace('/[^0-9.]/', '', $value) ?: 0);

        return round($negative ? -$number : $number, 2);
    }
}
