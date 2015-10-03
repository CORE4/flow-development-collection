<?php
namespace TYPO3\Flow\Aop\Pointcut;

/*
 * This file is part of the TYPO3.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Aop\JoinPointInterface;
use TYPO3\Flow\Cache\CacheManager;
use TYPO3\Flow\Cache\Frontend\PhpFrontend;
use TYPO3\Flow\Object\ObjectManagerInterface;

/**
 * An evaluator for AOP runtime expressions
 *
 * We expect that ALL runtime expressions are regenerated during compiletime. This currently does not support adding of expressions. See shutdownObject()
 *
 * @Flow\Scope("singleton")
 */
class RuntimeExpressionEvaluator
{
    /**
     * @var PhpFrontend
     */
    protected $runtimeExpressionsCache;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Currently existing runtime expressions loaded from cache.
     *
     * @var array
     */
    protected $runtimeExpressions = array();

    /**
     * Newly added expressions.
     *
     * @var array
     */
    protected $newExpressions = array();

    /**
     * This object is created very early and is part of the blacklisted "TYPO3\Flow\Aop" namespace so we can't rely on AOP for the property injection.
     *
     * @param ObjectManagerInterface $objectManager
     * @return void
     */
    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        if ($this->objectManager === null) {
            $this->objectManager = $objectManager;
            /** @var CacheManager $cacheManager */
            $cacheManager = $this->objectManager->get('TYPO3\Flow\Cache\CacheManager');
            $this->runtimeExpressionsCache = $cacheManager->getCache('Flow_Aop_RuntimeExpressions');
            $this->runtimeExpressions = $this->runtimeExpressionsCache->requireOnce('Flow_Aop_RuntimeExpressions');
        }
    }

    /**
     * Shutdown the Evaluator and save created expressions overwriting any existing expressions
     *
     * @return void
     */
    public function shutdownObject()
    {
        if ($this->newExpressions === array()) {
            return;
        }

        $codeToBeCached = 'return array (' . chr(10);

        foreach ($this->newExpressions as $name => $function) {
            $codeToBeCached .= "'" . $name . "' => " . $function . ',' . chr(10);
        }
        $codeToBeCached .= ');';
        $this->runtimeExpressionsCache->set('Flow_Aop_RuntimeExpressions', $codeToBeCached);
    }

    /**
     * Evaluate an expression with the given JoinPoint
     *
     * @param string $privilegeIdentifier MD5 hash that identifies a privilege
     * @param JoinPointInterface $joinPoint
     * @return mixed
     * @throws \TYPO3\Flow\Exception
     */
    public function evaluate($privilegeIdentifier, JoinPointInterface $joinPoint)
    {
        $functionName = $this->generateExpressionFunctionName($privilegeIdentifier);

        if (!$this->runtimeExpressions[$functionName] instanceof \Closure) {
            throw new \TYPO3\Flow\Exception('Runtime expression "' . $functionName . '" does not exist.', 1428694144);
        }

        return $this->runtimeExpressions[$functionName]->__invoke($joinPoint, $this->objectManager);
    }

    /**
     * Add expression to the evaluator
     *
     * @param string $privilegeIdentifier MD5 hash that identifies a privilege
     * @param string $expression
     * @return string
     */
    public function addExpression($privilegeIdentifier, $expression)
    {
        $this->newExpressions[$this->generateExpressionFunctionName($privilegeIdentifier)] = $expression;
    }

    /**
     * @param string $privilegeIdentifier MD5 hash that identifies a privilege
     * @return string
     */
    protected function generateExpressionFunctionName($privilegeIdentifier)
    {
        return 'flow_aop_expression_' . $privilegeIdentifier;
    }

    /**
     * Flush all runtime expressions
     *
     * @return void
     */
    public function flush()
    {
        $this->runtimeExpressionsCache->flush();
    }
}