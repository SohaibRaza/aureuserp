<?php

return [
    'navigation' => [
        'title'    => 'Evidence',
        'singular' => 'Evidence record',
    ],
    'purposes' => [
        'load_verification' => 'Load verification',
        'loading_photo'     => 'Loading photo',
        'delivery_receipt'  => 'Delivery receipt',
        'proof_of_delivery' => 'Proof of delivery',
    ],
    'table' => [
        'columns' => [
            'original-name' => 'File name',
            'purpose'       => 'Purpose',
            'file-type'     => 'File type',
            'file-size'     => 'Size in bytes',
            'uploaded-at'   => 'Uploaded at',
            'retain-until'  => 'Retain until',
        ],
        'views' => [
            'all' => 'All evidence',
        ],
        'empty-state' => [
            'heading'     => 'No evidence has been uploaded',
            'description' => 'Evidence files uploaded for the current Company will appear here.',
        ],
    ],
    'retention' => [
        'no-policy'      => 'No approved evidence retention period is configured for this Company. Uploads are disabled until an authorized user approves one.',
        'current-policy' => 'Current approved retention period: :days days (approved :approved).',
        'upload-blocked' => 'Evidence uploads are disabled until an authorized user approves a retention period for this Company.',
    ],
    'form' => [
        'file' => [
            'label' => 'PDF, PNG, or JPEG file',
        ],
        'purpose' => [
            'label' => 'Evidence purpose',
        ],
        'retention-days' => [
            'label' => 'Retention period in days',
        ],
        'reason' => [
            'label' => 'Approval reason',
        ],
        'invalid-upload' => 'The upload details are invalid. Please select an allowed file and purpose, then try again.',
    ],
    'actions' => [
        'download' => [
            'label' => 'Download',
        ],
        'upload' => [
            'label'       => 'Upload evidence',
            'description' => 'Files are stored privately and associated with the current Company.',
            'success'     => 'Evidence uploaded',
        ],
        'approve-retention' => [
            'label'       => 'Approve retention period',
            'description' => 'The recommended starting period is 2557 days (at least seven years). You can change it for this Company. Existing evidence keeps its original retention period. Files are not deleted automatically; legal holds and a separate disposal approval still apply.',
            'success'     => 'Retention period approved',
        ],
    ],
];
