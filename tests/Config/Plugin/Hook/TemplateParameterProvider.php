<?php

declare(strict_types=1);

namespace Psalm\Test\Config\Plugin\Hook;

use Override;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Types Box::get() as the receiver's type parameter, as given to the plugin, Box::all() as a list of it,
 * Box::consumer() as a closure taking it, Box::boxes() as a list of boxes of it and Box::boxConsumer() as a closure
 * taking a box of it; Source::markedItems() and Source::markedConsumer() as a Marker<int> intersected with a Holder
 * of a list of it and of a closure taking a box of it
 *
 * @psalm-suppress UnusedClass registered as a plugin via test config, instantiated by reflection
 */
final class TemplateParameterProvider implements MethodReturnTypeProviderInterface
{
    /**
     * @return array<string>
     * @psalm-pure
     */
    #[Override]
    public static function getClassLikeNames(): array
    {
        return ['Ns\Box', 'Ns\Source'];
    }

    /**
     * @psalm-capabilities read-props
     */
    #[Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $type_parameter = $event->getTemplateTypeParameters()[0] ?? null;

        if ($type_parameter === null) {
            return null;
        }

        return match ($event->getMethodNameLowercase()) {
            'get' => $type_parameter,
            'all' => Type::getList($type_parameter),
            'consumer' => new Union([new TClosure(
                [new FunctionLikeParameter('value', false, $type_parameter)],
                Type::getVoid(),
            )]),
            'boxes' => Type::getList(new Union([new TGenericObject('Ns\Box', [$type_parameter])])),
            'boxconsumer' => new Union([new TClosure(
                [new FunctionLikeParameter('box', false, new Union([new TGenericObject('Ns\Box', [$type_parameter])]))],
                Type::getVoid(),
            )]),
            'markeditems' => self::marked(Type::getList($type_parameter)),
            'markedconsumer' => self::marked(new Union([new TClosure(
                [new FunctionLikeParameter('box', false, new Union([new TGenericObject('Ns\Box', [$type_parameter])]))],
                Type::getVoid(),
            )])),
            default => null,
        };
    }

    /**
     * @psalm-pure
     */
    private static function marked(Union $held): Union
    {
        $marker = new TGenericObject('Ns\Marker', [Type::getInt()]);

        return new Union([$marker->setIntersectionTypes(['Ns\Holder' => new TGenericObject('Ns\Holder', [$held])])]);
    }
}
