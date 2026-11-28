<?php

namespace Ambengers\Kinetic\Tests\Features;

use Ambengers\Kinetic\Kinetic;
use Ambengers\Kinetic\Tests\BaseTestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;
use Inertia\Response;
use Inertia\ResponseFactory;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;

class RenderComposedPropsTest extends BaseTestCase
{
    public function test_composed_props_are_merged_into_the_rendered_page(): void
    {
        Inertia::composer('User/Profile', static function (Kinetic $inertia): void {
            $inertia->with('list', ['foo' => 'bar']);
        });

        $page = $this->page(app(ResponseFactory::class)->render('User/Profile', ['title' => 'Profile']));

        $this->assertSame('Profile', $page['props']['title']);
        $this->assertSame(['foo' => 'bar'], $page['props']['list']);
    }

    public function test_composed_props_override_page_props(): void
    {
        Inertia::composer('User/Profile', static function (Kinetic $inertia): void {
            $inertia->with('title', 'Composed');
        });

        $page = $this->page(app(ResponseFactory::class)->render('User/Profile', ['title' => 'Page']));

        $this->assertSame('Composed', $page['props']['title']);
    }

    public function test_backed_enum_component_resolves_composed_props(): void
    {
        Inertia::composer('User/Profile', static function (Kinetic $inertia): void {
            $inertia->with('list', ['foo' => 'bar']);
        });

        $page = $this->page(app(ResponseFactory::class)->render(BackedComponent::UserProfile));

        $this->assertSame('User/Profile', $page['component']);
        $this->assertSame(['foo' => 'bar'], $page['props']['list']);
    }

    public function test_unit_enum_component_resolves_composed_props(): void
    {
        Inertia::composer('UserProfile', static function (Kinetic $inertia): void {
            $inertia->with('list', ['foo' => 'bar']);
        });

        $page = $this->page(app(ResponseFactory::class)->render(UnitComponent::UserProfile));

        $this->assertSame('UserProfile', $page['component']);
        $this->assertSame(['foo' => 'bar'], $page['props']['list']);
    }

    public function test_provided_props_are_kept_and_composed_props_win(): void
    {
        Inertia::composer('User/Profile', static function (Kinetic $inertia): void {
            $inertia->with('title', 'Composed');
        });

        $page = $this->page(app(ResponseFactory::class)->render('User/Profile', new UserPageProps));

        $this->assertSame('Composed', $page['props']['title']);
        $this->assertSame('provider', $page['props']['source']);
    }

    public function test_composers_match_the_transformed_component_name(): void
    {
        app(ResponseFactory::class)->transformComponentUsing(
            static fn (string $component): string => strtolower($component)
        );
        Inertia::composer('user/profile', static function (Kinetic $inertia): void {
            $inertia->with('list', ['foo' => 'bar']);
        });

        $page = $this->page(app(ResponseFactory::class)->render('User/Profile'));

        $this->assertSame('user/profile', $page['component']);
        $this->assertSame(['foo' => 'bar'], $page['props']['list']);
    }

    public function test_component_transformer_runs_once(): void
    {
        $calls = 0;

        app(ResponseFactory::class)->transformComponentUsing(
            static function (string $component) use (&$calls): string {
                $calls++;

                return $component.'X';
            }
        );

        $page = $this->page(app(ResponseFactory::class)->render('User/Profile'));

        $this->assertSame(1, $calls);
        $this->assertSame('User/ProfileX', $page['component']);
    }

    public function test_invalid_component_does_not_run_composers(): void
    {
        $ran = false;

        Inertia::composer('*', static function () use (&$ran): void {
            $ran = true;
        });

        try {
            app(ResponseFactory::class)->render(IntComponent::Draft);
            $this->fail('An int-backed enum is not a valid Inertia component.');
        } catch (InvalidArgumentException) {
            $this->assertFalse($ran);
        }
    }

    public function test_composed_props_do_not_leak_into_the_next_render(): void
    {
        Inertia::composer('Index', static function (Kinetic $inertia): void {
            $inertia->with('pageTitle', 'Index');
        });

        $factory = app(ResponseFactory::class);
        $first = $this->page($factory->render('Index'));
        $second = $this->page($factory->render('Error', ['status' => 404]));

        $this->assertSame('Index', $first['props']['pageTitle']);
        $this->assertArrayNotHasKey('pageTitle', $second['props']);
    }

    public function test_failed_render_does_not_leak_composed_props(): void
    {
        Inertia::composer('Broken', [
            static function (Kinetic $inertia): void {
                $inertia->with('pageTitle', 'Broken');
            },
            static function (): never {
                throw new RuntimeException('Boom');
            },
        ]);

        $factory = app(ResponseFactory::class);

        try {
            $factory->render('Broken');
            $this->fail('The failing composer should interrupt the render.');
        } catch (RuntimeException) {
            // The composer stored a prop, then threw.
        }

        $this->assertArrayNotHasKey('pageTitle', $this->page($factory->render('Error'))['props']);
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

    private function page(Response $response): array
    {
        $request = Request::create('/user/profile', server: ['HTTP_X_INERTIA' => 'true']);
        $http = $response->toResponse($request);

        if (! $http instanceof JsonResponse) {
            throw new RuntimeException('Expected an Inertia JSON response.');
        }

        $page = $http->getData(true);

        if (! is_array($page)) {
            throw new RuntimeException('Expected an Inertia page array.');
        }

        return $page;
    }
}

enum BackedComponent: string
{
    case UserProfile = 'User/Profile';
}

enum UnitComponent
{
    case UserProfile;
}

enum IntComponent: int
{
    case Draft = 1;
}

class UserPageProps implements ProvidesInertiaProperties
{
    public function toInertiaProperties(RenderContext $context): iterable
    {
        return ['title' => 'Provider', 'source' => 'provider'];
    }
}
