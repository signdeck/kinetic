<?php

namespace Ambengers\Kinetic\Tests\Features;

use Ambengers\Kinetic\Kinetic;
use Ambengers\Kinetic\Tests\BaseTestCase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ResponseFactory;
use ReflectionMethod;

class RenderComposedPropsTest extends BaseTestCase
{
    public function test_composed_props_are_merged_into_the_rendered_page(): void
    {
        Inertia::composer('User/Profile', static function (ResponseFactory $inertia): void {
            $inertia->with('list', ['foo' => 'bar']);
        });

        $response = app(ResponseFactory::class)->render('User/Profile', ['title' => 'Profile']);

        $props = $this->props($response);

        $this->assertSame('Profile', $props['title']);
        $this->assertSame(['foo' => 'bar'], $props['list']);
    }

    public function test_composed_props_override_page_props(): void
    {
        Inertia::composer('User/Profile', static function (ResponseFactory $inertia): void {
            $inertia->with('title', 'Composed');
        });

        $response = app(ResponseFactory::class)->render('User/Profile', ['title' => 'Page']);

        $this->assertSame('Composed', $this->props($response)['title']);
    }

    /**
     * Inertia's ResponseFactory::render() declared `$component` untyped in 3.8.0,
     * so a narrower type in Kinetic is rejected by PHP with a fatal declaration
     * error as soon as the class is loaded.
     */
    public function test_render_does_not_narrow_the_component_parameter(): void
    {
        $parent = new ReflectionMethod(ResponseFactory::class, 'render');
        $child = new ReflectionMethod(Kinetic::class, 'render');

        $parentType = (string) $parent->getParameters()[0]->getType();
        $childType = (string) $child->getParameters()[0]->getType();

        $this->assertTrue(
            $childType === '' || $childType === $parentType,
            "Kinetic::render() parameter type [{$childType}] is narrower than the parent's [{$parentType}]."
        );
    }

    private function props(\Inertia\Response $response): array
    {
        $request = Request::create('/user/profile', server: ['HTTP_X_INERTIA' => 'true']);

        return $response->toResponse($request)->getData(true)['props'];
    }
}
