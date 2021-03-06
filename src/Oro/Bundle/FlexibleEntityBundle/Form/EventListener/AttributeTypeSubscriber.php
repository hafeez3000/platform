<?php
namespace Oro\Bundle\FlexibleEntityBundle\Form\EventListener;

use Symfony\Component\Form\Form;
use Oro\Bundle\FlexibleEntityBundle\Form\Type\AttributeOptionType;
use Oro\Bundle\FlexibleEntityBundle\AttributeType\AbstractAttributeType;
use Symfony\Component\Form\Event\DataEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Aims to customized attribute form type
 *
 * @author    Nicolas Dupont <nicolas@akeneo.com>
 * @copyright 2012 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 *
 */
class AttributeTypeSubscriber implements EventSubscriberInterface
{

    /**
     * Form factory
     * @var FormFactoryInterface
     */
    protected $factory;

    /**
     * Constructor
     * @param FormFactoryInterface $factory
     */
    public function __construct(FormFactoryInterface $factory = null)
    {
        $this->factory = $factory;
    }

    /**
     * List of subscribed events
     * @return multitype:string
     */
    public static function getSubscribedEvents()
    {
        return array(
            FormEvents::PRE_SET_DATA => 'preSetData'
        );
    }

    /**
     * Method called before set data
     * @param DataEvent $event
     */
    public function preSetData(DataEvent $event)
    {
        $data = $event->getData();

        if (null === $data) {
            return;
        }

        // only when editing
        if (is_null($data->getId()) === false) {

            $form = $event->getForm();

            // add related options
            if ($data->getBackendType() === AbstractAttributeType::BACKEND_TYPE_OPTION) {
                $this->addOptionCollection($form);
            }

            $this->disableField($form, 'code');
            $this->disableField($form, 'attributeType');
        }
    }

    /**
     * Add attribute option collection
     * @param Form $form
     */
    protected function addOptionCollection($form)
    {
        $form->add(
            $this->factory->createNamed(
                'options',
                'collection',
                null,
                array(
                    'type'         => new AttributeOptionType(),
                    'allow_add'    => true,
                    'allow_delete' => true,
                    'by_reference' => false
                )
            )
        );
    }

    /**
     * Disable a field from its name
     * @param Form   $form Form
     * @param string $name Field name
     */
    protected function disableField(Form $form, $name)
    {
        // get form field and properties
        $formField = $form->get($name);
        $type      = $formField->getConfig()->getType();
        $options   = $formField->getConfig()->getOptions();

        // replace by disabled and read-only
        $options['disabled']  = true;
        $options['read_only'] = true;
        $formField = $this->factory->createNamed($name, $type, null, $options);
        $form->add($formField);
    }
}
