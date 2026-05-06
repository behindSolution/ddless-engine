<?php
/**
 * Tests for the lazy-expand feature: ddless_normalize_value emits clickable
 * markers for objects past the depth cap, and ddless_lazy_expand resolves
 * them via reflection without invoking any methods.
 *
 * Run: php tests/php/LazyExpandTest.php
 */
require_once __DIR__ . '/bootstrap.php';

function with_lazy_enabled(callable $fn)
{
    $previous = $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] ?? false;
    $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = true;
    ddless_lazy_reset();
    try {
        return $fn();
    } finally {
        $GLOBALS['__DDLESS_LAZY_EXPAND_ENABLED__'] = $previous;
        ddless_lazy_reset();
    }
}

// ----------------------------------------------------------------------------
// Test fixtures
// ----------------------------------------------------------------------------

class LazyAgreement
{
    public int $id = 42;
    public string $title = 'NDA-2026';
    protected ?LazyParty $owner;
    private array $tags = ['urgent', 'legal'];
    private static string $tableName = 'agreements';

    public function __construct()
    {
        $this->owner = new LazyParty('Acme Inc.');
    }

    // Should NOT be invoked by lazy_expand
    public function getId(): int
    {
        $GLOBALS['__test_getter_called__'] = true;
        return $this->id;
    }

    public function getTags(): array
    {
        throw new \RuntimeException('this getter would explode if called');
    }
}

class LazyParty
{
    private string $name;
    public ?LazyAgreement $agreement = null; // for cycle test

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

class LazyWithUninitialized
{
    public string $loaded = 'ok';
    public string $never; // typed property left uninitialized
}

// Mock Doctrine proxy
if (!interface_exists('Doctrine\\Persistence\\Proxy')) {
    eval('namespace Doctrine\\Persistence; interface Proxy { public function __isInitialized(): bool; public function __load(): void; }');
}
class LazyDoctrineProxy implements \Doctrine\Persistence\Proxy
{
    public int $id = 7;
    private bool $initialized = false;

    public function __isInitialized(): bool
    {
        return $this->initialized;
    }

    public function __load(): void
    {
        $this->initialized = true;
    }
}

// ============================================================================

section('Lazy expand: marker emission');

test('flag on — past-depth object becomes a lazy marker', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        assert_true(is_array($marker), 'marker must be an array');
        assert_eq(true, $marker['__ddless_lazy__']);
        assert_eq('LazyAgreement', $marker['class']);
        assert_true(is_string($marker['token']) && str_starts_with($marker['token'], 'lz_'), 'token format');
    });
});

test('same object instance reuses the same token', function () {
    with_lazy_enabled(function () {
        $obj = new LazyAgreement();
        $a = ddless_normalize_value($obj, 99);
        $b = ddless_normalize_value($obj, 99);
        assert_eq($a['token'], $b['token'], 'identical references share a token');
    });
});

test('different instances of same class get different tokens', function () {
    with_lazy_enabled(function () {
        $a = ddless_normalize_value(new LazyAgreement(), 99);
        $b = ddless_normalize_value(new LazyAgreement(), 99);
        assert_true($a['token'] !== $b['token'], 'distinct refs get distinct tokens');
    });
});

section('Lazy expand: reflection-based property extraction');

test('expand returns public, protected, private props (without static)', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        $expanded = ddless_lazy_expand($marker['token']);

        assert_eq('LazyAgreement', $expanded['class']);
        assert_array_has_key('id', $expanded['children'], 'public id present');
        assert_array_has_key('title', $expanded['children'], 'public title present');
        assert_array_has_key('owner', $expanded['children'], 'protected owner present');
        assert_array_has_key('tags', $expanded['children'], 'private tags present');
        assert_array_not_has_key('tableName', $expanded['children'], 'static prop excluded');

        assert_eq('public', $expanded['children']['id']['visibility']);
        assert_eq('protected', $expanded['children']['owner']['visibility']);
        assert_eq('private', $expanded['children']['tags']['visibility']);

        assert_eq(42, $expanded['children']['id']['value']);
        assert_eq('NDA-2026', $expanded['children']['title']['value']);
        assert_eq(['urgent', 'legal'], $expanded['children']['tags']['value']);
    });
});

test('expand does NOT invoke getters (no side effects)', function () {
    with_lazy_enabled(function () {
        $GLOBALS['__test_getter_called__'] = false;
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        ddless_lazy_expand($marker['token']);
        assert_false($GLOBALS['__test_getter_called__'], 'getId() must not be called');
    });
});

test('expand survives even when object has a throwing getter', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        $expanded = ddless_lazy_expand($marker['token']);
        // If reflection accidentally called getTags() the throw would surface here.
        assert_array_has_key('children', $expanded);
    });
});

test('nested object property becomes its own lazy marker', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        $expanded = ddless_lazy_expand($marker['token']);
        $owner = $expanded['children']['owner']['value'];
        assert_true(is_array($owner) && !empty($owner['__ddless_lazy__']), 'nested object emits new marker');
        assert_eq('LazyParty', $owner['class']);
    });
});

test('uninitialized typed property reports placeholder, not error', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyWithUninitialized(), 99);
        $expanded = ddless_lazy_expand($marker['token']);
        assert_eq('[uninitialized]', $expanded['children']['never']['value']);
        assert_eq('ok', $expanded['children']['loaded']['value']);
    });
});

section('Lazy expand: cycle handling');

test('cycle resolves to the original token (no infinite recursion)', function () {
    with_lazy_enabled(function () {
        $agreement = new LazyAgreement();
        $party = new LazyParty('Acme');
        $party->agreement = $agreement;
        // Inject the cycle: agreement.owner.agreement === agreement
        $reflectionOwner = new \ReflectionProperty(LazyAgreement::class, 'owner');
        $reflectionOwner->setAccessible(true);
        $reflectionOwner->setValue($agreement, $party);

        $rootMarker = ddless_normalize_value($agreement, 99);
        $rootToken = $rootMarker['token'];

        $expanded = ddless_lazy_expand($rootToken);
        $partyMarker = $expanded['children']['owner']['value'];
        $partyExpanded = ddless_lazy_expand($partyMarker['token']);
        $cycleMarker = $partyExpanded['children']['agreement']['value'];

        assert_eq($rootToken, $cycleMarker['token'], 'cycle reuses the root token');
    });
});

section('Lazy expand: special object kinds');

test('Closure expand exposes file/line/parameters via reflection', function () {
    with_lazy_enabled(function () {
        $closure = function (string $a, int $b) { return $a . $b; };
        $marker = ddless_normalize_value($closure, 99);
        $expanded = ddless_lazy_expand($marker['token']);
        assert_eq('Closure', $expanded['class']);
        assert_eq(['a', 'b'], $expanded['children']['parameters']['value']);
        assert_true(is_string($expanded['children']['file']['value']), 'file resolved');
        assert_true(is_int($expanded['children']['line']['value']), 'line resolved');
    });
});

test('Doctrine uninitialized proxy is flagged, properties not pulled', function () {
    with_lazy_enabled(function () {
        $proxy = new LazyDoctrineProxy(); // initialized = false
        $marker = ddless_normalize_value($proxy, 99);
        $expanded = ddless_lazy_expand($marker['token']);
        assert_eq(true, $expanded['doctrineProxyUninitialized'] ?? null);
        assert_eq([], $expanded['children'], 'no SQL load triggered');
    });
});

test('Doctrine initialized proxy expands normally', function () {
    with_lazy_enabled(function () {
        $proxy = new LazyDoctrineProxy();
        $proxy->__load(); // mark as initialized
        $marker = ddless_normalize_value($proxy, 99);
        $expanded = ddless_lazy_expand($marker['token']);
        assert_array_not_has_key('doctrineProxyUninitialized', $expanded, 'no uninitialized flag once loaded');
        assert_eq(7, $expanded['children']['id']['value']);
    });
});

section('Lazy expand: classes with toArray() but no explicit serialization contract');

class FakeFrameworkRequest
{
    private string $foo = 'bar';
    // Mimics frameworks (Symfony Request, Laravel Request, etc.) where
    // toArray() has framework-specific semantics — should NOT be auto-called.
    public function toArray(): array
    {
        return [];
    }
}

test('object with toArray() but no JsonSerializable/Arrayable surfaces real state', function () {
    with_lazy_enabled(function () {
        $obj = new FakeFrameworkRequest();
        $marker = ddless_normalize_value($obj);
        assert_true(is_array($marker) && !empty($marker['__ddless_lazy__']), 'must become a lazy marker, not []');
        assert_eq('FakeFrameworkRequest', $marker['class']);

        $expanded = ddless_lazy_expand($marker['token']);
        assert_array_has_key('foo', $expanded['children'], 'reflection surfaces real properties');
        assert_eq('bar', $expanded['children']['foo']['value']);
    });
});

section('Lazy expand: error / lifecycle paths');

test('unknown token returns an error payload', function () {
    with_lazy_enabled(function () {
        $result = ddless_lazy_expand('lz_nope');
        assert_eq('token_not_found', $result['error']);
    });
});

test('ddless_lazy_reset clears registry and frees tokens', function () {
    with_lazy_enabled(function () {
        $marker = ddless_normalize_value(new LazyAgreement(), 99);
        $token = $marker['token'];
        ddless_lazy_reset();
        $result = ddless_lazy_expand($token);
        assert_eq('token_not_found', $result['error'], 'token invalid after reset');
        assert_count(0, $GLOBALS['__DDLESS_LAZY_REGISTRY__'], 'registry empty');
    });
});

test('registry honors LIMIT and emits placeholder marker when full', function () {
    with_lazy_enabled(function () {
        $previousLimit = $GLOBALS['__DDLESS_LAZY_REGISTRY_LIMIT__'];
        $GLOBALS['__DDLESS_LAZY_REGISTRY_LIMIT__'] = 2;
        try {
            $a = ddless_normalize_value(new LazyAgreement(), 99);
            $b = ddless_normalize_value(new LazyAgreement(), 99);
            $c = ddless_normalize_value(new LazyAgreement(), 99); // over limit

            assert_true(is_string($a['token']));
            assert_true(is_string($b['token']));
            assert_eq(null, $c['token'], '3rd registration returns null token');
            assert_eq('registry_full', $c['reason']);
        } finally {
            $GLOBALS['__DDLESS_LAZY_REGISTRY_LIMIT__'] = $previousLimit;
        }
    });
});

// ============================================================================

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(print_test_results());
}
