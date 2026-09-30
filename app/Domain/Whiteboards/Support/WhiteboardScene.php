<?php

declare(strict_types=1);

namespace Leantime\Domain\Whiteboards\Support;

use InvalidArgumentException;

/** Validate the portable Excalidraw snapshot before any persistence or AI edit. */
final class WhiteboardScene
{
    /** Only local, renderable shapes from the pinned Excalidraw 0.18 format. */
    private const ELEMENT_TYPES = [
        'rectangle', 'diamond', 'ellipse', 'text', 'line', 'arrow', 'freedraw', 'image', 'frame',
    ];

    public const MAX_ELEMENTS = 5000;

    public const MAX_FILES = 100;

    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public const MAX_TOTAL_FILE_BYTES = 32 * 1024 * 1024;

    public const MAX_SCENE_JSON_BYTES = 4 * 1024 * 1024;

    /** @return array{elements:array,appState:array,files:array} */
    public static function empty(): array
    {
        return ['elements' => [], 'appState' => ['viewBackgroundColor' => '#ffffff'], 'files' => []];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{scene:array{elements:array,appState:array,files:array},assets:list<array{file_id:string,mime_type:string,data_url:?string,byte_size:int}>}
     */
    public static function normalize(array $input): array
    {
        $elements = $input['elements'] ?? null;
        $appState = $input['appState'] ?? null;
        $files = $input['files'] ?? null;
        if (! is_array($elements) || ! array_is_list($elements) || count($elements) > self::MAX_ELEMENTS
            || ! is_array($appState) || ! is_array($files) || count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('Provide a valid Whiteboard scene.');
        }
        $elementIds = [];
        foreach ($elements as $element) {
            self::validateElement($element);
            if (isset($elementIds[$element['id']])) {
                throw new InvalidArgumentException('The Whiteboard contains duplicate element IDs.');
            }
            $elementIds[$element['id']] = true;
        }

        // Excalidraw exposes a large, volatile appState. Persist only settings that
        // describe the shared scene, not transient selection/UI/session state.
        $savedAppState = [
            'viewBackgroundColor' => (string) ($appState['viewBackgroundColor'] ?? '#ffffff'),
        ];
        if (! preg_match('/^#[0-9a-fA-F]{6}$/D', $savedAppState['viewBackgroundColor'])) {
            throw new InvalidArgumentException('Invalid Whiteboard background color.');
        }

        $sceneFiles = [];
        $assets = [];
        $totalBytes = 0;
        foreach ($files as $key => $file) {
            if (! is_array($file)) {
                throw new InvalidArgumentException('The Whiteboard contains an invalid image.');
            }
            $id = (string) ($file['id'] ?? $key);
            if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) || (string) $key !== $id) {
                throw new InvalidArgumentException('The Whiteboard contains an invalid image ID.');
            }
            $mime = (string) ($file['mimeType'] ?? '');
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                throw new InvalidArgumentException('Only PNG, JPEG, WebP, and GIF Whiteboard images are supported.');
            }
            $dataUrl = $file['dataURL'] ?? null;
            $bytes = 0;
            if ($dataUrl !== null) {
                if (! is_string($dataUrl)
                    || ! preg_match('~^data:'.preg_quote($mime, '~').';base64,([A-Za-z0-9+/=]+)$~D', $dataUrl, $matches)) {
                    throw new InvalidArgumentException('The Whiteboard image is not a valid local data URL.');
                }
                $decoded = base64_decode($matches[1], true);
                if ($decoded === false || $decoded === '' || strlen($decoded) > self::MAX_FILE_BYTES) {
                    throw new InvalidArgumentException('A Whiteboard image exceeds the 5 MiB limit.');
                }
                $bytes = strlen($decoded);
                $totalBytes += $bytes;
                if ($totalBytes > self::MAX_TOTAL_FILE_BYTES) {
                    throw new InvalidArgumentException('Whiteboard images exceed the 32 MiB total limit.');
                }
            }
            $created = isset($file['created']) && is_numeric($file['created']) ? (int) $file['created'] : 0;
            $sceneFiles[$id] = [
                'id' => $id,
                'mimeType' => $mime,
                'created' => $created,
                'lastRetrieved' => isset($file['lastRetrieved']) && is_numeric($file['lastRetrieved'])
                    ? (int) $file['lastRetrieved'] : $created,
            ];
            $assets[] = ['file_id' => $id, 'mime_type' => $mime, 'data_url' => $dataUrl, 'byte_size' => $bytes];
        }

        $scene = ['elements' => $elements, 'appState' => $savedAppState, 'files' => $sceneFiles];
        if (strlen(json_encode($scene, JSON_THROW_ON_ERROR)) > self::MAX_SCENE_JSON_BYTES) {
            throw new InvalidArgumentException('The Whiteboard scene exceeds the 4 MiB limit.');
        }

        return ['scene' => $scene, 'assets' => $assets];
    }

    /**
     * Excalidraw does not promise to repair arbitrary model-generated objects
     * supplied through initialData. Validate the native element shape before
     * persisting it so an MCP edit cannot make a shared board unrenderable.
     */
    private static function validateElement(mixed $element): void
    {
        if (! is_array($element) || ! self::validId($element['id'] ?? null)
            || ! in_array($element['type'] ?? null, self::ELEMENT_TYPES, true)) {
            throw new InvalidArgumentException('The Whiteboard contains an invalid element.');
        }
        foreach (['x', 'y', 'width', 'height', 'angle', 'strokeWidth', 'roughness', 'opacity',
            'seed', 'version', 'versionNonce', 'updated'] as $number) {
            if (! self::finiteNumber($element[$number] ?? null)) {
                throw new InvalidArgumentException('The Whiteboard element is missing valid geometry or version data.');
            }
        }
        if (abs($element['x']) > 1_000_000_000 || abs($element['y']) > 1_000_000_000
            || abs($element['width']) > 10_000_000 || abs($element['height']) > 10_000_000) {
            throw new InvalidArgumentException('The Whiteboard element exceeds the canvas limits.');
        }
        foreach (['strokeColor', 'backgroundColor'] as $color) {
            if (! is_string($element[$color] ?? null)
                || ! preg_match('/^(transparent|#[0-9a-fA-F]{3,8})$/D', $element[$color])) {
                throw new InvalidArgumentException('The Whiteboard element has an invalid color.');
            }
        }
        foreach (['roundness', 'index', 'frameId', 'boundElements', 'link'] as $nullableField) {
            if (! array_key_exists($nullableField, $element)) {
                throw new InvalidArgumentException('The Whiteboard element is missing a required field.');
            }
        }
        if (! in_array($element['fillStyle'] ?? null, ['hachure', 'cross-hatch', 'solid', 'zigzag'], true)
            || ! in_array($element['strokeStyle'] ?? null, ['solid', 'dashed', 'dotted'], true)
            || ! is_bool($element['isDeleted'] ?? null) || ! is_bool($element['locked'] ?? null)
            || ! is_array($element['groupIds'] ?? null) || ! array_is_list($element['groupIds'])
            || ! self::optionalId($element['frameId'] ?? null)
            || ! self::optionalId($element['index'] ?? null)
            || ! self::validBoundElements($element['boundElements'] ?? null)
            || ! self::validRoundness($element['roundness'] ?? null)
            || ! self::validLink($element['link'] ?? null)) {
            throw new InvalidArgumentException('The Whiteboard element is missing valid style or relationship data.');
        }
        foreach ($element['groupIds'] as $groupId) {
            if (! self::validId($groupId)) {
                throw new InvalidArgumentException('The Whiteboard element has an invalid group.');
            }
        }

        switch ($element['type']) {
            case 'text':
                if (! array_key_exists('containerId', $element)
                    || ! self::finiteNumber($element['fontSize'] ?? null)
                    || ! is_int($element['fontFamily'] ?? null)
                    || ! is_string($element['text'] ?? null)
                    || ! is_string($element['originalText'] ?? null)
                    || ! in_array($element['textAlign'] ?? null, ['left', 'center', 'right'], true)
                    || ! in_array($element['verticalAlign'] ?? null, ['top', 'middle', 'bottom'], true)
                    || ! self::optionalId($element['containerId'] ?? null)
                    || ! is_bool($element['autoResize'] ?? null)
                    || ! self::finiteNumber($element['lineHeight'] ?? null)) {
                    throw new InvalidArgumentException('The Whiteboard text element is incomplete.');
                }
                break;
            case 'line':
            case 'arrow':
                if (! array_key_exists('lastCommittedPoint', $element)
                    || ! array_key_exists('startBinding', $element)
                    || ! array_key_exists('endBinding', $element)
                    || ! array_key_exists('startArrowhead', $element)
                    || ! array_key_exists('endArrowhead', $element)
                    || ! self::validPoints($element['points'] ?? null)
                    || ! self::optionalPoint($element['lastCommittedPoint'] ?? null)
                    || ! self::validBinding($element['startBinding'] ?? null)
                    || ! self::validBinding($element['endBinding'] ?? null)
                    || ! self::validArrowhead($element['startArrowhead'] ?? null)
                    || ! self::validArrowhead($element['endArrowhead'] ?? null)
                    || ($element['type'] === 'arrow' && ! is_bool($element['elbowed'] ?? null))) {
                    throw new InvalidArgumentException('The Whiteboard line element is incomplete.');
                }
                break;
            case 'freedraw':
                if (! array_key_exists('lastCommittedPoint', $element)
                    || ! self::validPoints($element['points'] ?? null)
                    || ! is_array($element['pressures'] ?? null) || ! array_is_list($element['pressures'])
                    || ! is_bool($element['simulatePressure'] ?? null)
                    || ! self::optionalPoint($element['lastCommittedPoint'] ?? null)) {
                    throw new InvalidArgumentException('The Whiteboard drawing element is incomplete.');
                }
                foreach ($element['pressures'] as $pressure) {
                    if (! self::finiteNumber($pressure)) {
                        throw new InvalidArgumentException('The Whiteboard drawing pressure is invalid.');
                    }
                }
                break;
            case 'image':
                if (! array_key_exists('fileId', $element)
                    || ! array_key_exists('crop', $element)
                    || ! self::optionalId($element['fileId'] ?? null)
                    || ! in_array($element['status'] ?? null, ['pending', 'saved', 'error'], true)
                    || ! self::validPoint($element['scale'] ?? null)
                    || ! self::validCrop($element['crop'] ?? null)) {
                    throw new InvalidArgumentException('The Whiteboard image element is incomplete.');
                }
                break;
            case 'frame':
                if (! array_key_exists('name', $element)
                    || (! is_string($element['name']) && $element['name'] !== null)) {
                    throw new InvalidArgumentException('The Whiteboard frame name is invalid.');
                }
                break;
        }
    }

    private static function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $value) === 1;
    }

    private static function optionalId(mixed $value): bool
    {
        return $value === null || self::validId($value);
    }

    private static function validPoint(mixed $point): bool
    {
        return is_array($point) && array_is_list($point) && count($point) === 2
            && self::finiteNumber($point[0]) && self::finiteNumber($point[1]);
    }

    private static function optionalPoint(mixed $point): bool
    {
        return $point === null || self::validPoint($point);
    }

    private static function validPoints(mixed $points): bool
    {
        if (! is_array($points) || ! array_is_list($points) || $points === []) {
            return false;
        }
        foreach ($points as $point) {
            if (! self::validPoint($point)) {
                return false;
            }
        }

        return true;
    }

    private static function validBoundElements(mixed $boundElements): bool
    {
        if ($boundElements === null) {
            return true;
        }
        if (! is_array($boundElements) || ! array_is_list($boundElements)) {
            return false;
        }
        foreach ($boundElements as $bound) {
            if (! is_array($bound) || ! self::validId($bound['id'] ?? null)
                || ! in_array($bound['type'] ?? null, ['arrow', 'text'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function validRoundness(mixed $roundness): bool
    {
        return $roundness === null || (is_array($roundness)
            && self::finiteNumber($roundness['type'] ?? null)
            && (! isset($roundness['value']) || self::finiteNumber($roundness['value'])));
    }

    private static function validLink(mixed $link): bool
    {
        return $link === null || (is_string($link) && strlen($link) <= 2048
            && preg_match('~^(https?://|mailto:|/(?!/)|#)~i', $link) === 1);
    }

    private static function validBinding(mixed $binding): bool
    {
        return $binding === null || (is_array($binding)
            && self::validId($binding['elementId'] ?? null)
            && self::finiteNumber($binding['focus'] ?? null)
            && self::finiteNumber($binding['gap'] ?? null));
    }

    private static function validArrowhead(mixed $arrowhead): bool
    {
        return $arrowhead === null || in_array($arrowhead, [
            'arrow', 'bar', 'dot', 'circle', 'circle_outline', 'triangle', 'triangle_outline',
            'diamond', 'diamond_outline', 'crowfoot_one', 'crowfoot_many', 'crowfoot_one_or_many',
        ], true);
    }

    private static function validCrop(mixed $crop): bool
    {
        if ($crop === null) {
            return true;
        }
        if (! is_array($crop)) {
            return false;
        }
        foreach (['x', 'y', 'width', 'height', 'naturalWidth', 'naturalHeight'] as $key) {
            if (! self::finiteNumber($crop[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
