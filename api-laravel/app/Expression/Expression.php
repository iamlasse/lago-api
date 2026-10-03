<?php

declare(strict_types=1);

namespace App\Expression;

/**
 * A parsed expression AST node — the port of the gem's Expression enum.
 *
 * Sealed by convention: the only implementations are NumberNode, StringNode,
 * EventAttributeNode, UnaryMinusNode, BinaryOperationNode and FunctionNode,
 * mirroring the gem's variants.
 */
interface Expression {}
