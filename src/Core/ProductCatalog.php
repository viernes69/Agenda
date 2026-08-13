<?php
declare(strict_types=1);

namespace Agenduy\Core;

/**
 * Normaliza productos locales del tenant sin romper el esquema historico.
 */
final class ProductCatalog
{
    public const MAX_IMAGES = 4;

    /**
     * @return list<array{index:int,src:string,price:?float,cover:bool,label:string,has_custom_price:bool}>
     */
    public static function mediaForRow(array $row): array
    {
        $media = self::mediaFromJson($row);
        if ($media === []) {
            $media = self::mediaFromLegacyFields($row);
        }

        if ($media === []) {
            return [];
        }

        $media = array_slice($media, 0, self::MAX_IMAGES);
        $coverFound = false;
        foreach ($media as $idx => &$item) {
            $item['index'] = $idx;
            $item['label'] = trim((string)($item['label'] ?? ''));
            if ($item['label'] === '') {
                $item['label'] = count($media) > 1 ? 'Imagen ' . ($idx + 1) : '';
            }
            if (!empty($item['cover']) && !$coverFound) {
                $item['cover'] = true;
                $coverFound = true;
            } else {
                $item['cover'] = false;
            }
        }
        unset($item);

        if (!$coverFound) {
            $media[0]['cover'] = true;
        }

        usort($media, static function (array $a, array $b): int {
            if (!empty($a['cover']) && empty($b['cover'])) {
                return -1;
            }
            if (empty($a['cover']) && !empty($b['cover'])) {
                return 1;
            }
            return ($a['index'] ?? 0) <=> ($b['index'] ?? 0);
        });

        foreach ($media as $idx => &$item) {
            $item['index'] = $idx;
        }
        unset($item);

        return $media;
    }

    public static function discountPercent(array $row): float
    {
        foreach (['Descuento_Porcentaje', 'Descuento', 'discount_percent'] as $key) {
            if (!isset($row[$key]) || $row[$key] === '' || !is_numeric($row[$key])) {
                continue;
            }
            return max(0.0, min(100.0, round((float)$row[$key], 2)));
        }
        return 0.0;
    }

    public static function saleLabel(array $row): string
    {
        $label = trim((string)($row['Etiqueta_Venta'] ?? $row['Oferta_Tipo'] ?? ''));
        return mb_substr($label, 0, 60, 'UTF-8');
    }

    public static function basePrice(array $row): float
    {
        return is_numeric($row['Precio'] ?? null) ? max(0.0, (float)$row['Precio']) : 0.0;
    }

    public static function effectivePrice(float $price, float $discountPercent): float
    {
        $price = max(0.0, $price);
        if ($discountPercent <= 0.0) {
            return round($price, 2);
        }
        if ($discountPercent >= 100.0) {
            return 0.0;
        }
        return round($price * (1.0 - ($discountPercent / 100.0)), 2);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function indexFromRows(array $rows): array
    {
        $index = [];
        foreach ($rows as $idx => $row) {
            if ($idx === 0 || !is_array($row)) {
                continue;
            }
            $pid = $row['ID_Product'] ?? null;
            if ($pid === null || $pid === '') {
                continue;
            }
            $key = (string)$pid;
            $basePrice = self::basePrice($row);
            $discount = self::discountPercent($row);
            $media = self::mediaForRow($row);
            $variants = [];
            foreach ($media as $mediaItem) {
                $rawPrice = $mediaItem['price'] !== null ? (float)$mediaItem['price'] : $basePrice;
                $variants[] = [
                    'index' => (int)$mediaItem['index'],
                    'src' => (string)$mediaItem['src'],
                    'label' => (string)$mediaItem['label'],
                    'price' => self::effectivePrice($rawPrice, $discount),
                    'original_price' => round($rawPrice, 2),
                    'has_custom_price' => !empty($mediaItem['has_custom_price']),
                ];
            }
            if ($variants === []) {
                $variants[] = [
                    'index' => 0,
                    'src' => '',
                    'label' => '',
                    'price' => self::effectivePrice($basePrice, $discount),
                    'original_price' => round($basePrice, 2),
                    'has_custom_price' => false,
                ];
            }

            $index[$key] = [
                'id' => $key,
                'name' => trim((string)($row['Nombre'] ?? ('Producto ' . $key))),
                'type' => trim((string)($row['Tipo'] ?? '')),
                'price' => (float)$variants[0]['price'],
                'original_price' => (float)$variants[0]['original_price'],
                'discount_percent' => $discount,
                'sale_label' => self::saleLabel($row),
                'variants' => $variants,
            ];
        }
        return $index;
    }

    /**
     * @return array{product_id:string,variant:int,name:string,variant_label:string,price:float,original_price:float}
     */
    public static function resolveVariant(array $product, int $variantIndex): array
    {
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
        $variant = null;
        foreach ($variants as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ((int)($candidate['index'] ?? 0) === $variantIndex) {
                $variant = $candidate;
                break;
            }
        }
        if ($variant === null) {
            $variant = is_array($variants[0] ?? null) ? $variants[0] : [];
        }

        return [
            'product_id' => (string)($product['id'] ?? ''),
            'variant' => (int)($variant['index'] ?? 0),
            'name' => (string)($product['name'] ?? 'Producto'),
            'variant_label' => trim((string)($variant['label'] ?? '')),
            'price' => round((float)($variant['price'] ?? $product['price'] ?? 0), 2),
            'original_price' => round((float)($variant['original_price'] ?? $product['original_price'] ?? $product['price'] ?? 0), 2),
        ];
    }

    /**
     * @return list<array{index:int,src:string,price:?float,cover:bool,label:string,has_custom_price:bool}>
     */
    private static function mediaFromJson(array $row): array
    {
        $raw = $row['Imagenes'] ?? $row['Images'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = null;
        }
        if (!is_array($decoded)) {
            return [];
        }

        $media = [];
        foreach ($decoded as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $src = self::cleanPath((string)($item['src'] ?? $item['path'] ?? ''));
            if ($src === '') {
                continue;
            }
            $priceRaw = $item['price'] ?? $item['precio'] ?? null;
            $hasCustomPrice = $priceRaw !== null && $priceRaw !== '' && is_numeric($priceRaw);
            $media[] = [
                'index' => (int)$idx,
                'src' => $src,
                'price' => $hasCustomPrice ? max(0.0, (float)$priceRaw) : null,
                'cover' => !empty($item['cover']) || !empty($item['portada']),
                'label' => trim((string)($item['label'] ?? $item['title'] ?? $item['titulo'] ?? $item['name'] ?? '')),
                'has_custom_price' => $hasCustomPrice,
            ];
        }
        return $media;
    }

    /**
     * @return list<array{index:int,src:string,price:?float,cover:bool,label:string,has_custom_price:bool}>
     */
    private static function mediaFromLegacyFields(array $row): array
    {
        $paths = [];
        $cover = self::cleanPath((string)($row['Img_src'] ?? ''));
        if ($cover !== '') {
            $paths[] = $cover;
        }
        $galleryRaw = $row['Img_Gallery'] ?? '';
        $parts = is_array($galleryRaw) ? $galleryRaw : explode('|', (string)$galleryRaw);
        foreach ($parts as $part) {
            $path = self::cleanPath((string)$part);
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        $media = [];
        foreach (array_slice($paths, 0, self::MAX_IMAGES) as $idx => $path) {
            $media[] = [
                'index' => $idx,
                'src' => $path,
                'price' => null,
                'cover' => $idx === 0,
                'label' => '',
                'has_custom_price' => false,
            ];
        }
        return $media;
    }

    private static function cleanPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = str_replace('..', '', $path);
        return ltrim($path, '/');
    }
}
