<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SmsTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'template_name' => 'all_customers',
                'template' => "Dear {CUSTOMER_NAME}, Your {MONTH}'s bill is KSh {BILL_AMOUNT}, ID- {CUSTOMER_ID} Please pay before {LAST_DAY_OF_PAY_BILL}. -{COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "Example 1:\n</br>\n{CUSTOMER_NAME},\nThanks for being with us.\nID: {CUSTOMER_ID},\nIP {IP},\nPPPoE Username {PPPOE_USERNAME},\n- {COMPANY_NAME} {COMPANY_MOBILE}\n</br></br>\nExample 2:\n</br>\n{CUSTOMER_NAME},\nYour {MONTH}'s bill is KSh {BILL_AMOUNT},\nID- {CUSTOMER_ID}\nPlease pay before {LAST_DAY_OF_PAY_BILL}.\n-{COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'area_wise_customer_due_list',
                'template' => "Your NET service total due is KSh {AMOUNT}. ID- {CUSTOMER_ID}, Last day to pay the bill is {LAST_DAY_DUE_DATE} / {AUTO_TEMPORARY_DAY}, Please pay as soon as possible. - {COMPANY_NAME}",
                'template_ex_en' => "Your NET service total due is KSh {AMOUNT}.\nID- {CUSTOMER_ID},\nLast day to pay the bill is\n{LAST_DAY_DUE_DATE} / {AUTO_TEMPORARY_DAY},\nPlease pay as soon as possible.\n- {COMPANY_NAME}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'area_wise_customer_list',
                'template' => "Dear {CUSTOMER_NAME}, Let us know if any problems you may have with your service and advice us to help you with it. -{COMPANY_NAME},{COMPANY_MOBILE}",
                'template_ex_en' => "Example 1:\n</br>\n{CUSTOMER_NAME},\nLet us know if any problems you may have with your service and\nadvice us to help you with it.\n-{COMPANY_NAME},{COMPANY_MOBILE}\n</br></br>\nExample 2:\n</br>\nYour NET Service {MONTH}'s bill is KSh {BILL_AMOUNT}.\nPlease pay before {LAST_DAY_OF_PAY_BILL}.\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'auto_temporary_disable_alert',
                'template' => "Dear {CUSTOMER_NAME}, ID: {CUSTOMER_ID}, Your connection is temporarily disconnected. Due is KSh {DUE_AMOUNT}, - {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nID: {CUSTOMER_ID},\nYour connection is temporarily disconnected.\nDue is KSh {DUE_AMOUNT},\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'bill_generate',
                'template' => "Your NET {MONTH}'s bill is KSh {BILL_AMOUNT}, ID- {CUSTOMER_ID} Please pay before {LAST_DAY_OF_PAY_BILL}. -{COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "Your NET {MONTH}'s bill is KSh {BILL_AMOUNT},\nID- {CUSTOMER_ID}\nPlease pay before {LAST_DAY_OF_PAY_BILL}.\n-{COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'payment_collection',
                'template' => "Dear {CUSTOMER_NAME}, We got KSh {AMOUNT}, for {IP_OR_USER_NAME_OR_ID}, Your KSh {BALANCE}, - {COMPANY_NAME}",
                'template_ex_en' => "Dear {CUSTOMER_NAME}, We got KSh {AMOUNT}, for {IP_OR_USER_NAME_OR_ID}, Your KSh {BALANCE}, - {COMPANY_NAME}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'collection_(MFS)_to_owner',
                'template' => "Got a payment of {AMOUNT}, from {CUSTOMER_NAME}, ID- {CUSTOMER_ID}, at {PAYMENT_SYSTEM}.",
                'template_ex_en' => "Got a payment of {AMOUNT}, from {CUSTOMER_NAME},\nID- {CUSTOMER_ID},\nat {PAYMENT_SYSTEM}.",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'collection_delete',
                'template' => "{CUSTOMER_NAME},\nYour KSh {AMOUNT}.\nthe collection has been canceled.\nYou paid KSh {TOTAL_COLLECTION}.\nFor any questions,\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour KSh {AMOUNT}.\nthe collection has been canceled.\nYou paid KSh {TOTAL_COLLECTION}.\nFor any questions,\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'collection_edit',
                'template' => "{CUSTOMER_NAME},\nYour collection amount KSh {PREVIOUS_COLLECTION_AMOUNT}.\nhas been updated with KSh {CURRENT_COLLECTION_AMOUNT}.\nThanks- {COMPANY_NAME}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour collection amount KSh {PREVIOUS_COLLECTION_AMOUNT}.\nhas been updated with KSh {CURRENT_COLLECTION_AMOUNT}.\nThanks- {COMPANY_NAME}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'collection_to_owner',
                'template' => "Amount Collection {AMOUNT} for {CUSTOMER_NAME},\nID- {CUSTOMER_ID}\nat EasyEBilling Software.",
                'template_ex_en' => "Amount Collection {AMOUNT} for {CUSTOMER_NAME},\nID- {CUSTOMER_ID}\nat EasyEBilling Software.",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'complain_employee',
                'template' => "New Complain-\n{CUSTOMER_NAME},\nID: {CUSTOMER_ID},\nIP: {IP},\nPPPoE: {PPPOE_USERNAME},\n{CUSTOMER_MOBILE},\nComplain: {COMPLAINS},\nComment: {COMMENT},\nAddress: {CUSTOMER_ADDRESS}",
                'template_ex_en' => "New Complain-\n{CUSTOMER_NAME},\nID: {CUSTOMER_ID},\nIP: {IP},\nPPPoE: {PPPOE_USERNAME},\n{CUSTOMER_MOBILE},\nComplain: {COMPLAINS},\nComment: {COMMENT},\nAddress: {CUSTOMER_ADDRESS}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'complain_list',
                'template' => "Dear\n{CUSTOMER_NAME},\nYour problem has been resolved.\nIf needed give us a call {COMPANY_MOBILE}.\n- {COMPANY_NAME}",
                'template_ex_en' => "Dear\n{CUSTOMER_NAME},\nYour problem has been resolved.\nIf needed give us a call {COMPANY_MOBILE}.\n- {COMPANY_NAME}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'complain_to_customer',
                'template' => "{CUSTOMER_NAME},\nYour complain is: {COMPLAINS}, {COMMENT},\njust arised. {EMPLOYEE_NAME}, {EMPLOYEE_MOBILE}\nwill contact with you- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour complain is: {COMPLAINS}, {COMMENT},\njust arised. {EMPLOYEE_NAME}, {EMPLOYEE_MOBILE}\nwill contact with you- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'create_customer',
                'template' => "{CUSTOMER_NAME},\nID: {CUSTOMER_ID},\nIP: {IP},\nUsername: {PPPOE_USERNAME},\nEnjoy your new connection.\n-{COMPANY_NAME}",
                'template_ex_en' => "{CUSTOMER_NAME},\nID: {CUSTOMER_ID},\nIP: {IP},\nUsername: {PPPOE_USERNAME},\nEnjoy your new connection.\n-{COMPANY_NAME}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'create_customer_to_owner',
                'template' => "A new customer just created-\n{CUSTOMER_NAME},\nID- {CUSTOMER_ID},\nIP- {IP},\nPPPoE Username- {PPPOE_USERNAME}",
                'template_ex_en' => "A new customer just created-\n{CUSTOMER_NAME},\nID- {CUSTOMER_ID},\nIP- {IP},\nPPPoE Username- {PPPOE_USERNAME}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'free_customer_list',
                'template' => "Dear\n{CUSTOMER_NAME},\nYou are given a complimentary free connection.\nIf you have a query let us know.\n- {COMPANY_NAME} {COMPANY_MOBILE}",
                'template_ex_en' => "Dear\n{CUSTOMER_NAME},\nYou are given a complimentary free connection.\nIf you have a query let us know.\n- {COMPANY_NAME} {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'inactive_customer_list',
                'template' => "{CUSTOMER_NAME},\nYour connection still remains inactive.\nTotal due is KSh {DUE_AMOUNT}, to turn ON the connection\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour connection still remains inactive.\nTotal due is KSh {DUE_AMOUNT}, to turn ON the connection\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'failed_to_disable_at_mikrotik',
                'template' => "{CUSTOMER_NAME},\nID- {ID},\nIP- {IP},\nPPPoE ID- {PPPOE_USERNAME}\nEnjoy your internet connection.\n- {COMPANY_NAME} {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nID- {ID},\nIP- {IP},\nPPPoE ID- {PPPOE_USERNAME}\nEnjoy your internet connection.\n- {COMPANY_NAME} {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
            [
                'template_name' => 'temporary_disable_customer_list',
                'template' => "{CUSTOMER_NAME},\nYour NET service is temporarily disconnected. \nThe due amount is KSh {DUE_AMOUNT},\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour NET service is temporarily disconnected. \nThe due amount is KSh {DUE_AMOUNT},\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => true,
            ],
            [
                'template_name' => 'reminder',
                'template' => "{CUSTOMER_NAME},\nYour connection will be discontinued from\n{AUTO_TEMPORARY_DAY}. Please pay your bill.\nID- {ID},\nDue- KSh {DUE_AMOUNT},\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_en' => "{CUSTOMER_NAME},\nYour connection will be discontinued from\n{AUTO_TEMPORARY_DAY}. Please pay your bill.\nID- {ID},\nDue- KSh {DUE_AMOUNT},\n- {COMPANY_NAME}, {COMPANY_MOBILE}",
                'template_ex_bn' => '',
                'is_active' => false,
            ],
        ];

        foreach ($templates as $template) {
            DB::table('sms_templates')->updateOrInsert(
                ['template_name' => $template['template_name']],
                [
                    'template' => $template['template'],
                    'template_ex_en' => $template['template_ex_en'],
                    'template_ex_bn' => $template['template_ex_bn'],
                    'is_active' => $template['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
