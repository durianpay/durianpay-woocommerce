<?php

class Test_Plugin_Metadata extends WP_UnitTestCase
{
    public function testMetadata()
    {
        $pluginData = get_plugin_data(PLUGIN_DIR . '/woo-durianpay.php');

        $this->assertSame('1 Razorpay: Signup for FREE PG', $pluginData['Name']);

        $version = $pluginData['Version'];
        $v = explode(".", $version);
        $this->assertSame(3, count($v));

        $this->assertSame('Team Durianpay', $pluginData['AuthorName']);

        $this->assertSame('https://durianpay.com', $pluginData['AuthorURI']);

        $this->assertSame('https://durianpay.com', $pluginData['PluginURI']);

        $this->assertSame('Durianpay Payment Gateway Integration for WooCommerce.Durianoay Welcome Back <cite>By <a href="https://durianpay.com">Team Durianpay</a>.</cite>', $pluginData['Description']);
    }
}

