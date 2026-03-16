<?php
declare(strict_types=1);

namespace Paypercut\Payment\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddSubscriptionProductAttribute implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * @inheritdoc
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Subscription Enabled attribute
        $eavSetup->addAttribute(
            Product::ENTITY,
            'paypercut_subscription_enabled',
            [
                'type' => 'int',
                'label' => 'Paypercut Subscription Enabled',
                'input' => 'boolean',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'required' => false,
                'default' => '0',
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => true,
                'unique' => false,
                'apply_to' => 'simple,virtual,downloadable',
                'group' => 'Paypercut Subscriptions',
                'sort_order' => 10,
                'note' => 'Enable this product for Paypercut recurring subscription purchases'
            ]
        );

        // Subscription Price (optional override)
        $eavSetup->addAttribute(
            Product::ENTITY,
            'paypercut_subscription_price',
            [
                'type' => 'decimal',
                'label' => 'Subscription Price',
                'input' => 'price',
                'backend' => \Magento\Catalog\Model\Product\Attribute\Backend\Price::class,
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => true,
                'unique' => false,
                'apply_to' => 'simple,virtual,downloadable',
                'group' => 'Paypercut Subscriptions',
                'sort_order' => 20,
                'note' => 'Special price for subscription orders. Leave empty to use regular price.'
            ]
        );

        // Subscription Interval Override
        $eavSetup->addAttribute(
            Product::ENTITY,
            'paypercut_subscription_interval',
            [
                'type' => 'varchar',
                'label' => 'Subscription Interval',
                'input' => 'select',
                'source' => \Paypercut\Payment\Model\Adminhtml\Source\BillingInterval::class,
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => 'simple,virtual,downloadable',
                'group' => 'Paypercut Subscriptions',
                'sort_order' => 30,
                'note' => 'Override the default billing interval for this product. Leave empty to use default.'
            ]
        );

        // Subscription Interval Count Override
        $eavSetup->addAttribute(
            Product::ENTITY,
            'paypercut_subscription_interval_count',
            [
                'type' => 'int',
                'label' => 'Subscription Interval Count',
                'input' => 'text',
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => 'simple,virtual,downloadable',
                'group' => 'Paypercut Subscriptions',
                'sort_order' => 40,
                'frontend_class' => 'validate-number',
                'note' => 'Number of intervals between billings. Leave empty to use default.'
            ]
        );

        // Maximum Billing Cycles (subscription duration)
        $eavSetup->addAttribute(
            Product::ENTITY,
            'paypercut_subscription_cycles',
            [
                'type' => 'int',
                'label' => 'Maximum Billing Cycles',
                'input' => 'text',
                'required' => false,
                'global' => ScopedAttributeInterface::SCOPE_WEBSITE,
                'visible' => true,
                'user_defined' => true,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'unique' => false,
                'apply_to' => 'simple,virtual,downloadable',
                'group' => 'Paypercut Subscriptions',
                'sort_order' => 50,
                'frontend_class' => 'validate-number',
                'note' => 'Maximum number of billing cycles. Leave empty for unlimited subscription.'
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}

