<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

enum Permission: string
{
    case InvoiceRead = 'invoice.read';
    case InvoiceWrite = 'invoice.write';
    case InvoiceIssue = 'invoice.issue';
    case InvoiceCancel = 'invoice.cancel';
    case PaymentWrite = 'payment.write';
    case ClientRead = 'client.read';
    case ClientWrite = 'client.write';
    case ProjectWrite = 'project.write';
    case PriceListManage = 'price_list.manage';
    case SupplierSettingsManage = 'supplier_settings.manage';
    case SupplierMembersManage = 'supplier_members.manage';
    case SupplierBrandingManage = 'supplier_branding.manage';
    case SupplierExportsRead = 'supplier_exports.read';
    case PlatformSettingsManage = 'platform_settings.manage';
    case PlatformUsersManage = 'platform_users.manage';
    case PlatformUpdateManage = 'platform_update.manage';
}
