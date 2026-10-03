<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// The helpers of scripts/dump-api.php that write one symbol as PHP source: its types, its values,
// its members and its header.

namespace Gatepost\Postcode\Scripts;

use BackedEnum;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use UnitEnum;

const SELF_NAMES = ['self', 'static', 'parent'];
const ENUM_METHODS = ['cases', 'from', 'tryFrom'];
const ENUM_INTERFACES = [UnitEnum::class, BackedEnum::class];

// A name inside the namespace of the symbol loses that namespace. Any other class name keeps its
// whole name, with a backslash in front, so that a reader cannot take it for a local name.
function relativeName(string $name, string $namespace): string
{
    $prefix = $namespace . '\\';

    return \str_starts_with($name, $prefix) ? \substr($name, \strlen($prefix)) : '\\' . $name;
}

/**
 * @param array<string, string> $aliases The class names that PHP 8.5 gives for self and parent,
 *                                       each with the word that PHP 8.1 to 8.4 give.
 */
function typeText(?ReflectionType $type, string $namespace, array $aliases): string
{
    if ($type instanceof ReflectionNamedType) {
        // PHP 8.5 reports the class for a type written self, and older versions report self. The
        // dump writes self for both, so that it does not depend on the version.
        $name = $aliases[$type->getName()] ?? $type->getName();
        $isClass = !$type->isBuiltin() && !\in_array($name, SELF_NAMES, true);
        $text = $isClass ? relativeName($name, $namespace) : $name;
        $isNullable = $type->allowsNull() && !\in_array($name, ['mixed', 'null'], true);

        return $isNullable ? "?{$text}" : $text;
    }
    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $separator = $type instanceof ReflectionUnionType ? '|' : '&';

        return \implode($separator, \array_map(
            static fn(ReflectionType $part): string => typeText($part, $namespace, $aliases),
            $type->getTypes(),
        ));
    }

    return '';
}

function floatLiteral(float $value): ?string
{
    $json = \json_encode($value, JSON_PRESERVE_ZERO_FRACTION);

    return $json === false ? null : $json;
}

function stringLiteral(string $value): ?string
{
    if (\preg_match('/\A[ -~]*\z/', $value) !== 1) {
        return null;
    }

    return "'" . \strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
}

// The script writes only the values that its tests cover. A value of another kind stops the run,
// so that the dump never holds a wrong or a missing value.
function literal(mixed $value, string $namespace): ?string
{
    return match (true) {
        $value === null => 'null',
        \is_bool($value) => $value ? 'true' : 'false',
        \is_int($value) => (string) $value,
        \is_float($value) => floatLiteral($value),
        \is_string($value) => stringLiteral($value),
        $value === [] => '[]',
        $value instanceof UnitEnum => relativeName($value::class, $namespace) . '::' . $value->name,
        default => null,
    };
}

function unwritable(string $what, mixed $value): RuntimeException
{
    $type = \is_string($value)
        ? 'string with a character outside printable ASCII'
        : \get_debug_type($value);

    return new RuntimeException(
        "Cannot write {$what}. The script writes null, booleans, numbers, printable ASCII text, "
        . "an empty array and enum cases, but the value has the type {$type}.",
    );
}

// The doc comments of the package say @internal for a public symbol that only the package calls.
// Only a tag counts: the word in the text of a doc, such as a parameter, hides nothing.
function isInternal(string|false $docComment): bool
{
    return $docComment !== false
        && \preg_match('/^[ \t]*(?:\/\*\*|\*)?[ \t]*@internal\b/m', $docComment) === 1;
}

/**
 * The class names that stand for self and parent in a type, as PHP 8.5 reports them.
 *
 * @param ReflectionClass<object> $class
 *
 * @return array<string, string>
 */
function typeAliases(ReflectionClass $class): array
{
    $parent = $class->getParentClass();

    return [$class->name => 'self', ...($parent === false ? [] : [$parent->name => 'parent'])];
}

/**
 * @param array<string, string> $aliases
 */
function parameterText(ReflectionParameter $parameter, string $namespace, array $aliases): string
{
    $type = typeText($parameter->getType(), $namespace, $aliases);
    $text = ($type === '' ? '' : "{$type} ") . ($parameter->isPassedByReference() ? '&' : '')
        . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->name;
    if (!$parameter->isDefaultValueAvailable()) {
        return $text;
    }
    $default = $parameter->getDefaultValue();
    $owner = ($parameter->getDeclaringClass()->name ?? '') . '::'
        . $parameter->getDeclaringFunction()->name . '()';

    return $text . ' = ' . (literal($default, $namespace)
        ?? throw unwritable("the default value of \${$parameter->name} in {$owner}", $default));
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<string>
 */
function caseLines(ReflectionClass $class, string $namespace): array
{
    if (!$class instanceof ReflectionEnum) {
        return [];
    }
    $lines = [];
    foreach ($class->getCases() as $case) {
        if (!$case instanceof ReflectionEnumBackedCase) {
            $lines[] = "case {$case->name};";
            continue;
        }
        $value = $case->getBackingValue();
        $what = "the value of the case {$class->name}::{$case->name}";
        $lines[] = "case {$case->name} = " . (literal($value, $namespace)
            ?? throw unwritable($what, $value)) . ';';
    }

    return $lines;
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<string>
 */
function publicConstantLines(ReflectionClass $class, string $namespace): array
{
    $lines = [];
    foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        $isOwn = $constant->getDeclaringClass()->name === $class->name;
        if (!$isOwn || $constant->isEnumCase() || isInternal($constant->getDocComment())) {
            continue;
        }
        $value = $constant->getValue();
        $lines[] = ($constant->isFinal() ? 'final ' : '') . "public const {$constant->name} = "
            . (literal($value, $namespace) ?? throw unwritable(
                "the value of the constant {$class->name}::{$constant->name}",
                $value,
            )) . ';';
    }

    return $lines;
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<string>
 */
function propertyLines(ReflectionClass $class, string $namespace): array
{
    // PHP gives each enum the properties name and value, and an enum cannot declare more.
    $properties = $class->isEnum() ? [] : $class->getProperties(ReflectionProperty::IS_PUBLIC);
    $lines = [];
    foreach ($properties as $property) {
        $isOwn = $property->getDeclaringClass()->name === $class->name;
        if (!$isOwn || isInternal($property->getDocComment())) {
            continue;
        }
        $type = typeText($property->getType(), $namespace, typeAliases($class));
        $lines[] = 'public ' . ($property->isStatic() ? 'static ' : '')
            . ($property->isReadOnly() ? 'readonly ' : '')
            . ($type === '' ? '' : "{$type} ") . '$' . $property->name . ';';
    }

    return $lines;
}

/**
 * @param ReflectionClass<object> $class
 */
function methodLine(ReflectionClass $class, ReflectionMethod $method, string $namespace): string
{
    $aliases = typeAliases($class);
    $parameters = \array_map(
        static fn(ReflectionParameter $parameter): string => parameterText(
            $parameter,
            $namespace,
            $aliases,
        ),
        $method->getParameters(),
    );
    $returns = typeText($method->getReturnType(), $namespace, $aliases);
    // An interface method is abstract without saying so.
    $isAbstract = $method->isAbstract() && !$class->isInterface();
    $modifiers = ($isAbstract ? 'abstract ' : '') . ($method->isFinal() ? 'final ' : '')
        . 'public ' . ($method->isStatic() ? 'static ' : '');

    return "{$modifiers}function {$method->name}(" . \implode(', ', $parameters) . ')'
        . ($returns === '' ? '' : ": {$returns}") . ';';
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<string>
 */
function methodLines(ReflectionClass $class, string $namespace): array
{
    $lines = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $isOwn = $method->getDeclaringClass()->name === $class->name;
        $isImplicit = $class->isEnum() && \in_array($method->name, ENUM_METHODS, true);
        if ($isOwn && !$isImplicit && !isInternal($method->getDocComment())) {
            $lines[] = methodLine($class, $method, $namespace);
        }
    }

    return $lines;
}

function clause(string $keyword, string $names): string
{
    return $names === '' ? '' : " {$keyword} {$names}";
}

/**
 * The interfaces that the symbol names itself. A parent class gives the others, and PHP adds two
 * to each enum.
 *
 * @param ReflectionClass<object> $class
 */
function interfaceNames(ReflectionClass $class, string $namespace): string
{
    $parent = $class->getParentClass();
    $inherited = $parent === false ? [] : $parent->getInterfaceNames();
    $names = \array_diff($class->getInterfaceNames(), $inherited, ENUM_INTERFACES);
    \sort($names);

    return \implode(', ', \array_map(
        static fn(string $name): string => relativeName($name, $namespace),
        $names,
    ));
}

/**
 * @param ReflectionClass<object> $class
 */
function classHeader(ReflectionClass $class, string $namespace): string
{
    $name = $class->getShortName();
    $interfaces = interfaceNames($class, $namespace);
    if ($class->isInterface()) {
        return "interface {$name}" . clause('extends', $interfaces);
    }
    $parent = $class->getParentClass();
    $tail = clause('extends', $parent === false ? '' : relativeName($parent->name, $namespace))
        . clause('implements', $interfaces);
    if ($class instanceof ReflectionEnum) {
        $backing = $class->getBackingType();

        return "enum {$name}" . ($backing === null ? '' : ": {$backing}") . $tail;
    }

    return ($class->isAbstract() ? 'abstract ' : '') . ($class->isFinal() ? 'final ' : '')
        . "class {$name}{$tail}";
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<string>
 */
function classBlock(ReflectionClass $class, string $namespace): array
{
    $members = [
        ...caseLines($class, $namespace),
        ...publicConstantLines($class, $namespace),
        ...propertyLines($class, $namespace),
        ...methodLines($class, $namespace),
    ];

    return [
        classHeader($class, $namespace),
        '{',
        ...\array_map(static fn(string $member): string => "    {$member}", $members),
        '}',
    ];
}
