<?php

return [
    'groups' => [
        'contacts' => ['label' => 'Contacts', 'subgroups' => [
            'records' => ['label' => 'Contact Records', 'permissions' => [
                'contacts.view' => 'View and search contacts',
                'contacts.create' => 'Create contacts',
                'contacts.import' => 'Import contacts',
                'contacts.edit' => 'Edit contact information',
                'contacts.archive' => 'Archive or reactivate contacts',
                'contacts.export' => 'Export contacts',
            ]],
            'documents' => ['label' => 'Documents', 'permissions' => [
                'contact_documents.view' => 'View contact document metadata',
                'contact_documents.upload' => 'Upload contact documents',
                'contact_documents.download' => 'Download general contact documents',
                'contact_documents.delete' => 'Delete contact documents',
                'contact_identity_documents.download' => 'Download passport and identity documents',
            ]],
            'bank_details' => ['label' => 'Bank Details', 'permissions' => [
                'contact_bank_details.view' => 'View contact bank details',
                'contact_bank_details.edit' => 'Create and edit contact bank details',
            ]],
            'governance' => ['label' => 'Audit and Notifications', 'permissions' => [
                'contact_download_activity.view' => 'View contact document download activity',
                'contact_notifications.receive' => 'Receive sensitive contact document download notifications',
            ]],
        ]],
        'tags' => ['label' => 'Tags', 'permissions' => [
            'tags.view' => 'View tags and their contacts',
            'tags.manage' => 'Create, edit, and delete tags',
            'tags.manage_contacts' => 'Add or remove contacts from tags',
            'tags.export' => 'Export tag contacts',
        ]],
        'contact_media' => ['label' => 'Contact Media', 'permissions' => [
            'contact_media.view' => 'View contact files and media',
            'contact_media.upload' => 'Create media groups and upload files',
            'contact_media.edit' => 'Rename groups and replace files',
            'contact_media.delete' => 'Delete groups and files',
            'contact_media.restore' => 'Restore deleted groups and files',
        ]],
        'contact_submissions' => ['label' => 'Contact Submissions', 'permissions' => [
            'contact_submissions.view' => 'View incoming submissions',
            'contact_submissions.migrate' => 'Convert submissions into contacts',
            'contact_submissions.delete' => 'Delete submissions',
        ]],
        'whatsapp' => ['label' => 'WhatsApp', 'subgroups' => [
            'templates' => ['label' => 'Templates', 'permissions' => [
                'whatsapp.templates.view' => 'View WhatsApp templates',
                'whatsapp.templates.create' => 'Create WhatsApp templates',
                'whatsapp.test_message.send' => 'Send test template messages',
            ]],
            'campaigns' => ['label' => 'Campaigns', 'permissions' => [
                'whatsapp.campaigns.view' => 'View campaigns and recipients',
                'whatsapp.campaigns.create' => 'Create campaigns',
                'whatsapp.campaigns.edit' => 'Edit draft or scheduled campaigns',
                'whatsapp.campaigns.send' => 'Send or schedule campaigns',
                'whatsapp.campaigns.cancel' => 'Cancel campaigns',
                'whatsapp.campaigns.export' => 'Export campaign reports',
            ]],
            'inbox' => ['label' => 'Inbox', 'permissions' => [
                'whatsapp.inbox.view' => 'View conversations and messages',
                'whatsapp.inbox.reply' => 'Reply to conversations',
                'whatsapp.inbox.mark_read' => 'Mark conversations as read',
            ]],
            'reports' => ['label' => 'Reports', 'permissions' => [
                'whatsapp.responses.view' => 'View WhatsApp responses',
                'whatsapp.responses.export' => 'Export WhatsApp responses',
            ]],
        ]],
        'activity' => ['label' => 'Activity', 'permissions' => [
            'activity.view_own' => 'View own activity',
            'activity.view_all' => 'View activity for all users',
            'activity.view_contact' => 'View a contact history',
        ]],
        'mentions' => ['label' => 'Mentions and Notifications', 'permissions' => [
            'mentions.read' => 'Read messages and mentions',
            'mentions.create' => 'Create messages and mention users',
        ]],
        'users' => ['label' => 'Users and Access Control', 'permissions' => [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit user details',
            'users.ban' => 'Ban or unban users',
            'users.resend_invitation' => 'Resend account setup email',
            'users.assign_roles' => 'Assign roles to users',
            'roles.manage' => 'Create and edit roles and permissions',
        ]],
    ],
];
