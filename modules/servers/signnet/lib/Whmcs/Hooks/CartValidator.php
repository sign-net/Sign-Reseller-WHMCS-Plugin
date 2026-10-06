<?php

declare(strict_types=1);

namespace SignNet\Whmcs\Hooks;

use SignNet\Whmcs\Catalogue\PortalFields;
use SignNet\Whmcs\Catalogue\WhmcsCatalogue;
use SignNet\Whmcs\Db\ServiceRepository;
use SignNet\Whmcs\Provisioning\HostnameNormalizer;
use SignNet\Whmcs\Provisioning\InvalidOrderException;

/**
 * Stops a checkout (ShoppingCartValidateCheckout) whose Sign.net portals could not be created: an
 * address that is not a hostname, the same address twice in the cart, or an address a service
 * already uses. Sign.net never lets a hostname be used twice, even after its portal is deleted.
 *
 * @phpstan-type CartProduct array{pid: int, domain: string, customfields: array<array-key, mixed>}
 */
final class CartValidator
{
    /**
     * @param mixed $cartProducts The cart's products as WHMCS keeps them in the session: each with
     *     "pid", "domain" and "customfields" (custom field id => value).
     *
     * @return list<string> Messages for the customer, as HTML.
     */
    public function validate(mixed $cartProducts): array
    {
        $services = new ServiceRepository();
        $errors = [];
        $seen = [];
        foreach ($this->portalOrders(self::products($cartProducts)) as $order) {
            try {
                $host = HostnameNormalizer::normalize($order['address']);
            } catch (InvalidOrderException $invalid) {
                $errors[] = $order['product'] . ': ' . $invalid->getMessage();
                continue;
            }
            if (isset($seen[$host])) {
                $errors[] = sprintf('%s is in your cart more than once. Each portal needs its own address.', $host);
            } elseif ($services->hostnameInUse($host)) {
                $errors[] = sprintf('%s is already in use. Choose another portal address.', $host);
            }
            $seen[$host] = true;
        }

        return array_map(CustomerHtml::escape(...), array_values(array_unique($errors)));
    }

    /**
     * The cart's Sign.net products, each with the portal address it asks for.
     *
     * @param list<CartProduct> $products
     *
     * @return list<array{product: string, address: string}>
     */
    private function portalOrders(array $products): array
    {
        $productIds = array_column($products, 'pid');
        $names = array_intersect_key(WhmcsCatalogue::signNetProducts(), array_flip($productIds));
        $hostFields = PortalFields::hostFieldIds(array_keys($names));
        $orders = [];
        foreach ($products as $product) {
            if (isset($names[$product['pid']])) {
                $orders[] = [
                    'product' => $names[$product['pid']],
                    'address' => self::address($product, $hostFields[$product['pid']] ?? []),
                ];
            }
        }

        return $orders;
    }

    /**
     * The portal address field's value, or the product's domain when that field is missing or blank,
     * which is also how the module reads it.
     *
     * @param CartProduct $product
     * @param list<int> $hostFieldIds
     */
    private static function address(array $product, array $hostFieldIds): string
    {
        foreach ($hostFieldIds as $fieldId) {
            $value = $product['customfields'][$fieldId] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $product['domain'];
    }

    /**
     * @return list<CartProduct>
     */
    private static function products(mixed $cartProducts): array
    {
        $products = [];
        foreach (is_array($cartProducts) ? $cartProducts : [] as $product) {
            if (!is_array($product)) {
                continue;
            }
            $products[] = [
                'pid' => is_numeric($product['pid'] ?? null) ? (int) $product['pid'] : 0,
                'domain' => is_string($product['domain'] ?? null) ? $product['domain'] : '',
                'customfields' => is_array($product['customfields'] ?? null) ? $product['customfields'] : [],
            ];
        }

        return $products;
    }
}
