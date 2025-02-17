<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 project.
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the MIT License (MIT). For the full copyright and license information,
 * please read the LICENSE file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\HtmlSanitizer\Visitor;

use DOMAttr;
use DOMElement;
use DOMNode;
use DOMText;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Context;

/**
 * Node visitor handling most common aspects for tag, attribute
 * and values as declared in provided `Behavior` instance.
 */
class CommonVisitor extends AbstractVisitor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var Behavior
     */
    protected $behavior;

    /**
     * @var Context
     */
    protected $context;

    public function __construct(Behavior $behavior)
    {
        $this->logger = new NullLogger();
        $this->behavior = $behavior;
    }

    public function beforeTraverse(Context $context): void
    {
        $this->context = $context;
    }

    public function enterNode(DOMNode $node): ?DOMNode
    {
        if (!$node instanceof DOMElement) {
            return $node;
        }
        $tag = $this->behavior->getTag($node->nodeName);
        if ($tag === null) {
            // pass custom elements, in case it has been declared
            if ($this->behavior->shallAllowCustomElements() && $this->isCustomElement($node)) {
                $this->logger->debug('Allowed custom element {nodeName}', [
                    'behavior' => $this->behavior->getName(),
                    'nodeName' => $node->nodeName,
                ]);
                return $node;
            }
            $this->logger->debug('Found unexpected tag {nodeName}', [
                'behavior' => $this->behavior->getName(),
                'nodeName' => $node->nodeName,
            ]);
            if ($this->behavior->shallEncodeInvalidTag()) {
                return $this->convertToText($node);
            }
            return null;
        }
        $node = $this->processAttributes($node, $tag);
        $node = $this->processChildren($node, $tag);
        // completely remove node, in case it is expect to exist with attributes only
        if ($node !== null && $node->attributes->length === 0 && $tag->shallPurgeWithoutAttrs()) {
            return null;
        }
        return $node;
    }

    public function leaveNode(DOMNode $node): ?DOMNode
    {
        if (!$node instanceof DOMElement) {
            return $node;
        }
        $tag = $this->behavior->getTag($node->nodeName);
        if ($tag === null) {
            // pass custom elements, in case it has been declared
            if ($this->behavior->shallAllowCustomElements() && $this->isCustomElement($node)) {
                return $node;
            }
            // unexpected node, that should have been handled in `enterNode` already
            return null;
        }
        // completely remove node, in case it is expect to exist with children only
        if ($node->childNodes->length === 0 && $tag->shallPurgeWithoutChildren()) {
            return null;
        }
        return $node;
    }

    protected function processAttributes(?DOMElement $node, Behavior\Tag $tag): ?DOMElement
    {
        if ($node === null) {
            return null;
        }
        // reverse processing of attributes,
        // allowing to directly remove attribute nodes
        for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
            /** @var DOMAttr $attribute */
            $attribute = $node->attributes->item($i);
            try {
                $this->processAttribute($node, $tag, $attribute);
            } catch (Behavior\NodeException $exception) {
                return $exception->getNode();
            }
        }
        return $node;
    }

    protected function processChildren(?DOMElement $node, Behavior\Tag $tag): ?DOMElement
    {
        if ($node === null) {
            return null;
        }
        if (!$tag->shallAllowChildren()
            && $node->childNodes->length > 0
            && $this->behavior->shallRemoveUnexpectedChildren()
        ) {
            $this->logger->debug('Found unexpected children for {nodeName}', [
                'behavior' => $this->behavior->getName(),
                'nodeName' => $node->nodeName,
            ]);
            // reverse processing of children,
            // allowing to directly remove child nodes
            for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                /** @var DOMNode $child */
                $child = $node->childNodes->item($i);
                $node->removeChild($child);
            }
        }
        return $node;
    }

    /**
     * @param DOMElement $node
     * @param Behavior\Tag $tag
     * @param DOMAttr $attribute
     * @throws Behavior\NodeException
     */
    protected function processAttribute(DOMElement $node, Behavior\Tag $tag, DOMAttr $attribute): void
    {
        $name = strtolower($attribute->name);
        $attr = $tag->getAttr($name);
        if ($attr === null || !$attr->matchesValue($attribute->value)) {
            $this->logger->debug('Found invalid attribute {nodeName}.{attrName}', [
                'behavior' => $this->behavior->getName(),
                'nodeName' => $node->nodeName,
                'attrName' => $attribute->nodeName,
            ]);
            $this->handleInvalidAttr($node, $name);
        }
    }

    /**
     * @param DOMNode $node
     * @param string $name
     * @throws Behavior\NodeException
     */
    protected function handleInvalidAttr(DOMNode $node, string $name): void
    {
        if ($this->behavior->shallEncodeInvalidAttr()) {
            throw Behavior\NodeException::create()->withNode($this->convertToText($node));
        }
        if (!$node instanceof DOMElement) {
            throw Behavior\NodeException::create()->withNode(null);
        }
        $node->removeAttribute($name);
    }

    /**
     * Converts node/element to text node, basically disarming tags.
     * (`<script>` --> `&lt;script&gt;` when DOM is serialized as string)
     *
     * @param DOMNode $node
     * @return DOMText
     */
    protected function convertToText(DOMNode $node): DOMText
    {
        $text = new DOMText();
        $text->nodeValue = $this->context->parser->saveHTML($node);
        return $text;
    }

    /**
     * Whether given node name can be considered as custom element.
     * (see https://html.spec.whatwg.org/multipage/custom-elements.html#valid-custom-element-name)
     *
     * @param DOMNode $node
     * @return bool
     */
    protected function isCustomElement(DOMNode $node): bool
    {
        return $node instanceof DOMElement
            && preg_match('#^[a-z][a-z0-9]*-.+#', $node->nodeName) > 0;
    }
}
