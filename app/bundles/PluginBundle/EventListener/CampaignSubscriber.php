<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\PluginBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Mautic\PluginBundle\PluginEvents;

/**
 * Class CampaignSubscriber.
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * CampaignSubscriber constructor.
     *
     * @param IntegrationHelper $integrationHelper
     */
    public function __construct(IntegrationHelper $integrationHelper)
    {
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD        => ['onCampaignBuild', 0],
            PluginEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    /**
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $action = [
            'label'       => 'mautic.plugin.actions.push_lead',
            'description' => 'mautic.plugin.actions.tooltip',
            'formType'    => 'integration_list',
            'formTheme'   => 'MauticPluginBundle:FormTheme\Integration',
            'eventName'   => PluginEvents::ON_CAMPAIGN_TRIGGER_ACTION,
        ];

        $event->addAction('plugin.leadpush', $action);
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerAction(CampaignExecutionEvent $event)
    {
        $config = $event->getConfig();
        $lead   = $event->getLead();

        $integration             = (!empty($config['integration'])) ? $config['integration'] : null;
        $integrationCampaign     = (!empty($config['config']['campaigns'])) ? $config['config']['campaigns'] : null;
        $integrationMemberStatus = (!empty($config['campaign_member_status']['campaign_member_status']))
            ? $config['campaign_member_status']['campaign_member_status'] : null;
        $services                = $this->integrationHelper->getIntegrationObjects($integration);
        $success                 = true;
        $errors                  = [];

        /**
         * @var
         * @var AbstractIntegration $s
         */
        foreach ($services as $name => $s) {
            $settings = $s->getIntegrationSettings();
            if (!$settings->isPublished()) {
                continue;
            }

            $personIds = null;
            if (method_exists($s, 'pushLead')) {
                if (!$personIds = $s->resetLastIntegrationError()->pushLead($lead, $config)) {
                    $success = false;
                    if ($error = $s->getLastIntegrationError()) {
                        $errors[] = $error;
                    }
                }
            }

            if ($success && $integrationCampaign && method_exists($s, 'pushLeadToCampaign')) {
                if (!$s->resetLastIntegrationError()->pushLeadToCampaign($lead, $integrationCampaign, $integrationMemberStatus, $personIds)) {
                    $success = false;
                    if ($error = $s->getLastIntegrationError()) {
                        $errors[] = $error;
                    }
                }
            }
        }

        if (count($errors)) {
            $event->setFailed(implode('<br />', $errors));
        }

        return $event->setResult($success);
    }
}
