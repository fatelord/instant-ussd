<?php

namespace Bitmarshals\InstantUssd;

use Zend\EventManager\Event;
use Zend\ServiceManager\ServiceLocatorInterface;
use Exception;
use Bitmarshals\InstantUssd\InstantUssd;
use ArrayObject;

/**
 * Description of UssdEvent
 *
 * @author David Bwire
 */
class UssdEvent extends Event
{

    /**
     *
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Get ServicelocatorInterface that is already set or try accessing it from event target
     *
     * @return ServiceLocatorInterface
     * @throws \Exception
     */
    public function getServiceLocator()
    {
        if ($this->serviceLocator instanceof ServiceLocatorInterface) {
            return $this->serviceLocator;
        } elseif (method_exists($this->getTarget(), 'getServiceLocator')) {
            // try accessing it from the event target
            $sl = $this->getTarget()->getServiceLocator();
            if ($sl instanceof ServiceLocatorInterface) {
                $this->serviceLocator = $sl;
                return $this->serviceLocator;
            }
        } else {
            throw new Exception('ServiceLocator is not accessible.');
        }
    }

    /**
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return $this
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        return $this;
    }

    /**
     *
     * @return bool
     */
    public function containsIncomingData()
    {

        $isIncomingData = $this->getParam('is_incoming_data', false);
        return ($isIncomingData === true);
    }

    /**
     *
     * @return mixed string|null
     */
    public function getLatestResponse()
    {
        return $this->getParam('latest_response', null);
    }

    /**
     *
     * @return mixed string|null
     */
    public function getFirstResponse()
    {
        return $this->getParam('first_response', null);
    }

    /**
     *
     * @return mixed array|null
     */
    public function getNonExtraneousValues()
    {
        return $this->getParam('a_values_non_extraneous');
    }

    /**
     * Returns the class (essentially the controller) which initialized InstantUssd class
     *
     * @return object
     * @throws Exception
     */
    public function getInitializer()
    {
        $eventTarget = $this->target;
        if (gettype($eventTarget) === 'object') {
            return $eventTarget->getInitializer();
        } else {
            throw new Exception('Event target must be an object');
        }
    }

    /**
     * Retreives an instance of InstantUssd from which UssdEvent was triggered or throws an error
     *
     * @return InstantUssd
     * @throws Exception
     */
    public function getInstantUssd()
    {
        $eventTarget = $this->target;
        if (is_object($eventTarget) && ($eventTarget instanceof InstantUssd)) {
            return $eventTarget;
        }
        throw new Exception("UssdEvent was not triggered from InstantUssd class");
    }

    /**
     *
     * @param array $menuConfig
     */
    public function attachDynamicErrors(array $menuConfig)
    {

        $errorMessage = $this->getParam('error_message', "");
        $menuConfig['has_error'] = !$this->getParam('is_valid', true);

        $defaultErrorMessage = array_key_exists('error_message', $menuConfig) ? $menuConfig['error_message'] : "";
        if ($menuConfig['has_error'] &&
            empty($defaultErrorMessage)) {
            $menuConfig['error_message'] = $errorMessage;
        }
    }

}
