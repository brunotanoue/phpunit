<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Util;

use const PHP_OS;
use const PHP_OS_FAMILY;
use const PHP_VERSION;
use function addcslashes;
use function array_flip;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_unshift;
use function assert;
use function class_exists;
use function explode;
use function extension_loaded;
use function function_exists;
use function get_class;
use function ini_get;
use function is_array;
use function is_int;
use function method_exists;
use function phpversion;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExecutionOrderDependency;
use PHPUnit\Framework\InvalidDataProviderException;
use PHPUnit\Framework\SelfDescribing;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSize\TestSize;
use PHPUnit\Metadata\Annotation\Registry as AnnotationRegistry;
use PHPUnit\Metadata\Covers;
use PHPUnit\Metadata\CoversClass;
use PHPUnit\Metadata\CoversFunction;
use PHPUnit\Metadata\CoversMethod;
use PHPUnit\Metadata\DataProvider;
use PHPUnit\Metadata\Group;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\Metadata\Registry as MetadataRegistry;
use PHPUnit\Metadata\RequiresFunction;
use PHPUnit\Metadata\RequiresMethod;
use PHPUnit\Metadata\RequiresOperatingSystem;
use PHPUnit\Metadata\RequiresOperatingSystemFamily;
use PHPUnit\Metadata\RequiresPhp;
use PHPUnit\Metadata\RequiresPhpExtension;
use PHPUnit\Metadata\RequiresPhpunit;
use PHPUnit\Metadata\RequiresSetting;
use PHPUnit\Metadata\TestWith;
use PHPUnit\Metadata\Uses;
use PHPUnit\Metadata\UsesClass;
use PHPUnit\Metadata\UsesFunction;
use PHPUnit\Metadata\UsesMethod;
use PHPUnit\Runner\Version;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Traversable;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Test
{
    private static array $hookMethods = [];

    /**
     * @psalm-return array{0: string, 1: string}
     *
     * @throws \SebastianBergmann\RecursionContext\InvalidArgumentException
     */
    public static function describe(\PHPUnit\Framework\Test $test): array
    {
        if ($test instanceof TestCase) {
            return [get_class($test), $test->getName()];
        }

        if ($test instanceof SelfDescribing) {
            return ['', $test->toString()];
        }

        return ['', get_class($test)];
    }

    public static function describeAsString(\PHPUnit\Framework\Test $test): string
    {
        if ($test instanceof SelfDescribing) {
            return $test->toString();
        }

        return get_class($test);
    }

    /**
     * @psalm-param class-string $className
     *
     * @psalm-return list<string>
     */
    public static function getMissingRequirements(string $className, string $methodName): array
    {
        $missing = [];

        foreach (MetadataRegistry::parser()->forClassAndMethod($className, $methodName) as $metadata) {
            if ($metadata->isRequiresPhp()) {
                assert($metadata instanceof RequiresPhp);

                if (!$metadata->versionRequirement()->isSatisfiedBy(PHP_VERSION)) {
                    $missing[] = sprintf(
                        'PHP %s is required.',
                        $metadata->versionRequirement()->asString()
                    );
                }
            }

            if ($metadata->isRequiresPhpExtension()) {
                assert($metadata instanceof RequiresPhpExtension);

                if (!extension_loaded($metadata->extension()) ||
                    ($metadata->hasVersionRequirement() &&
                     !$metadata->versionRequirement()->isSatisfiedBy(phpversion($metadata->extension())))) {
                    $missing[] = sprintf(
                        'PHP extension %s%s is required.',
                        $metadata->extension(),
                        $metadata->hasVersionRequirement() ? (' ' . $metadata->versionRequirement()->asString()) : ''
                    );
                }
            }

            if ($metadata->isRequiresPhpunit()) {
                assert($metadata instanceof RequiresPhpunit);

                if (!$metadata->versionRequirement()->isSatisfiedBy(Version::id())) {
                    $missing[] = sprintf(
                        'PHPUnit %s is required.',
                        $metadata->versionRequirement()->asString()
                    );
                }
            }

            if ($metadata->isRequiresOperatingSystemFamily()) {
                assert($metadata instanceof RequiresOperatingSystemFamily);

                if ($metadata->operatingSystemFamily() !== PHP_OS_FAMILY) {
                    $missing[] = sprintf(
                        'Operating system %s is required.',
                        $metadata->operatingSystemFamily()
                    );
                }
            }

            if ($metadata->isRequiresOperatingSystem()) {
                assert($metadata instanceof RequiresOperatingSystem);

                $pattern = sprintf(
                    '/%s/i',
                    addcslashes($metadata->operatingSystem(), '/')
                );

                if (!preg_match($pattern, PHP_OS)) {
                    $missing[] = sprintf(
                        'Operating system %s is required.',
                        $metadata->operatingSystem()
                    );
                }
            }

            if ($metadata->isRequiresFunction()) {
                assert($metadata instanceof RequiresFunction);

                if (!function_exists($metadata->functionName())) {
                    $missing[] = sprintf(
                        'Function %s() is required.',
                        $metadata->functionName()
                    );
                }
            }

            if ($metadata->isRequiresMethod()) {
                assert($metadata instanceof RequiresMethod);

                if (!method_exists($metadata->className(), $metadata->methodName())) {
                    $missing[] = sprintf(
                        'Method %s::%s() is required.',
                        $metadata->className(),
                        $metadata->methodName()
                    );
                }
            }

            if ($metadata->isRequiresSetting()) {
                assert($metadata instanceof RequiresSetting);

                if (ini_get($metadata->setting()) !== $metadata->value()) {
                    $missing[] = sprintf(
                        'Setting "%s" is required to be "%s".',
                        $metadata->setting(),
                        $metadata->value()
                    );
                }
            }
        }

        return $missing;
    }

    /**
     * @psalm-param class-string $className
     *
     * @throws Exception
     */
    public static function providedData(string $className, string $methodName): ?array
    {
        $dataProvider = MetadataRegistry::parser()->forMethod($className, $methodName)->isDataProvider();
        $testWith     = MetadataRegistry::parser()->forMethod($className, $methodName)->isTestWith();

        if ($dataProvider->isEmpty() && $testWith->isEmpty()) {
            return self::dataProvidedByTestWithAnnotation($className, $methodName);
        }

        if ($dataProvider->isNotEmpty()) {
            $data = self::dataProvidedByMethods($dataProvider);
        } else {
            $data = self::dataProvidedByMetadata($testWith);
        }

        if ($data === []) {
            throw new SkippedTestError(
                'Skipped due to empty data set provided by data provider'
            );
        }

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                throw new InvalidDataSetException(
                    sprintf(
                        'Data set %s is invalid.',
                        is_int($key) ? '#' . $key : '"' . $key . '"'
                    )
                );
            }
        }

        return $data;
    }

    /**
     * @psalm-param class-string $className
     */
    public static function parseTestMethodAnnotations(string $className, ?string $methodName = ''): array
    {
        $registry = AnnotationRegistry::getInstance();

        if ($methodName !== null) {
            try {
                return [
                    'method' => $registry->forMethod($className, $methodName)->symbolAnnotations(),
                    'class'  => $registry->forClassName($className)->symbolAnnotations(),
                ];
            } catch (Exception $methodNotFound) {
                // ignored
            }
        }

        return [
            'method' => null,
            'class'  => $registry->forClassName($className)->symbolAnnotations(),
        ];
    }

    /**
     * @psalm-param class-string $className
     *
     * @return ExecutionOrderDependency[]
     */
    public static function getDependencies(string $className, string $methodName): array
    {
        $annotations = self::parseTestMethodAnnotations(
            $className,
            $methodName
        );

        $dependsAnnotations = $annotations['class']['depends'] ?? [];

        if (isset($annotations['method']['depends'])) {
            $dependsAnnotations = array_merge(
                $dependsAnnotations,
                $annotations['method']['depends']
            );
        }

        // Normalize dependency name to className::methodName
        $dependencies = [];

        foreach ($dependsAnnotations as $value) {
            $dependencies[] = ExecutionOrderDependency::fromDependsAnnotation($className, $value);
        }

        return array_unique($dependencies);
    }

    /**
     * @psalm-param class-string $className
     */
    public static function groups(string $className, string $methodName): array
    {
        $groups = [];

        foreach (MetadataRegistry::parser()->forClassAndMethod($className, $methodName) as $metadata) {
            assert($metadata instanceof Metadata);

            if ($metadata->isGroup()) {
                assert($metadata instanceof Group);

                $groups[] = $metadata->groupName();
            }

            if ($metadata->isCoversClass() || $metadata->isCoversMethod() || $metadata->isCoversFunction()) {
                assert($metadata instanceof CoversClass || $metadata instanceof CoversMethod || $metadata instanceof CoversFunction);

                $groups[] = '__phpunit_covers_' . self::canonicalizeName($metadata->asStringForCodeUnitMapper());
            }

            if ($metadata->isCovers()) {
                assert($metadata instanceof Covers);

                $groups[] = '__phpunit_covers_' . self::canonicalizeName($metadata->target());
            }

            if ($metadata->isUsesClass() || $metadata->isUsesMethod() || $metadata->isUsesFunction()) {
                assert($metadata instanceof UsesClass || $metadata instanceof UsesMethod || $metadata instanceof UsesFunction);

                $groups[] = '__phpunit_uses_' . self::canonicalizeName($metadata->asStringForCodeUnitMapper());
            }

            if ($metadata->isUses()) {
                assert($metadata instanceof Uses);

                $groups[] = '__phpunit_uses_' . self::canonicalizeName($metadata->target());
            }
        }

        return array_unique($groups);
    }

    /**
     * @psalm-param class-string $className
     */
    public static function size(string $className, string $methodName): TestSize
    {
        $groups = array_flip(self::groups($className, $methodName));

        if (isset($groups['large'])) {
            return TestSize::large();
        }

        if (isset($groups['medium'])) {
            return TestSize::medium();
        }

        if (isset($groups['small'])) {
            return TestSize::small();
        }

        return TestSize::unknown();
    }

    /**
     * @psalm-param class-string $className
     */
    public static function hookMethods(string $className): array
    {
        if (!class_exists($className, false)) {
            return self::emptyHookMethodsArray();
        }

        if (isset(self::$hookMethods[$className])) {
            return self::$hookMethods[$className];
        }

        self::$hookMethods[$className] = self::emptyHookMethodsArray();

        try {
            foreach ((new ReflectionClass($className))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === Assert::class) {
                    continue;
                }

                if ($method->getDeclaringClass()->getName() === TestCase::class) {
                    continue;
                }

                $metadata = MetadataRegistry::parser()->forMethod($className, $method->getName());

                if ($method->isStatic()) {
                    if ($metadata->isBeforeClass()->isNotEmpty()) {
                        array_unshift(
                            self::$hookMethods[$className]['beforeClass'],
                            $method->getName()
                        );
                    }

                    if ($metadata->isAfterClass()->isNotEmpty()) {
                        self::$hookMethods[$className]['afterClass'][] = $method->getName();
                    }
                }

                if ($metadata->isBefore()->isNotEmpty()) {
                    array_unshift(
                        self::$hookMethods[$className]['before'],
                        $method->getName()
                    );
                }

                if ($metadata->isPreCondition()->isNotEmpty()) {
                    array_unshift(
                        self::$hookMethods[$className]['preCondition'],
                        $method->getName()
                    );
                }

                if ($metadata->isPostCondition()->isNotEmpty()) {
                    self::$hookMethods[$className]['postCondition'][] = $method->getName();
                }

                if ($metadata->isAfter()->isNotEmpty()) {
                    self::$hookMethods[$className]['after'][] = $method->getName();
                }
            }
        } catch (ReflectionException $e) {
        }

        return self::$hookMethods[$className];
    }

    public static function isTestMethod(ReflectionMethod $method): bool
    {
        if (!$method->isPublic()) {
            return false;
        }

        if (strpos($method->getName(), 'test') === 0) {
            return true;
        }

        $metadata = MetadataRegistry::parser()->forMethod(
            $method->getDeclaringClass()->getName(),
            $method->getName()
        );

        return $metadata->isTest()->isNotEmpty();
    }

    private static function emptyHookMethodsArray(): array
    {
        return [
            'beforeClass'   => ['setUpBeforeClass'],
            'before'        => ['setUp'],
            'preCondition'  => ['assertPreConditions'],
            'postCondition' => ['assertPostConditions'],
            'after'         => ['tearDown'],
            'afterClass'    => ['tearDownAfterClass'],
        ];
    }

    private static function canonicalizeName(string $name): string
    {
        return strtolower(trim($name, '\\'));
    }

    private static function dataProvidedByMethods(MetadataCollection $dataProvider): array
    {
        $result = [];

        foreach ($dataProvider as $_dataProvider) {
            assert($_dataProvider instanceof DataProvider);

            try {
                $class  = new ReflectionClass($_dataProvider->className());
                $method = $class->getMethod($_dataProvider->methodName());
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                throw new Exception(
                    $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
                // @codeCoverageIgnoreEnd
            }

            if ($method->isStatic()) {
                $object = null;
            } else {
                $object = $class->newInstanceWithoutConstructor();
            }

            if ($method->getNumberOfParameters() === 0) {
                $data = $method->invoke($object);
            } else {
                $data = $method->invoke($object, $_dataProvider->methodName());
            }

            if ($data instanceof Traversable) {
                $origData = $data;
                $data     = [];

                foreach ($origData as $key => $value) {
                    if (is_int($key)) {
                        $data[] = $value;
                    } elseif (array_key_exists($key, $data)) {
                        throw new InvalidDataProviderException(
                            sprintf(
                                'The key "%s" has already been defined by a previous data provider',
                                $key,
                            )
                        );
                    } else {
                        $data[$key] = $value;
                    }
                }
            }

            if (is_array($data)) {
                $result = array_merge($result, $data);
            }
        }

        return $result;
    }

    private static function dataProvidedByMetadata(MetadataCollection $testWith): array
    {
        $result = [];

        foreach ($testWith as $_testWith) {
            assert($_testWith instanceof TestWith);

            $result[] = $_testWith->data();
        }

        return $result;
    }

    /**
     * @psalm-param class-string $className
     *
     * @throws Exception
     */
    private static function dataProvidedByTestWithAnnotation(string $className, string $methodName): ?array
    {
        $docComment = (new ReflectionMethod($className, $methodName))->getDocComment();

        if (!$docComment) {
            return null;
        }

        $docComment = str_replace("\r\n", "\n", $docComment);
        $docComment = preg_replace('/' . '\n' . '\s*' . '\*' . '\s?' . '/', "\n", $docComment);
        $docComment = (string) substr($docComment, 0, -1);
        $docComment = rtrim($docComment, "\n");

        if (!preg_match('/@testWith\s+/', $docComment, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $offset            = strlen($matches[0][0]) + $matches[0][1];
        $annotationContent = substr($docComment, $offset);
        $data              = [];

        foreach (explode("\n", $annotationContent) as $candidateRow) {
            $candidateRow = trim($candidateRow);

            if ($candidateRow[0] !== '[') {
                break;
            }

            $dataSet = json_decode($candidateRow, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(
                    'The data set for the @testWith annotation cannot be parsed: ' . json_last_error_msg()
                );
            }

            $data[] = $dataSet;
        }

        if (!$data) {
            throw new Exception('The data set for the @testWith annotation cannot be parsed.');
        }

        return $data;
    }
}
