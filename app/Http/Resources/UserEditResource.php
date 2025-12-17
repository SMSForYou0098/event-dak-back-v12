<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserEditResource extends JsonResource
{
    /**
     * Additional data to be included with the resource.
     */
    protected array $additionalData = [];

    /**
     * Set additional data for the resource.
     */
    public function withAdditionalData(array $data): self
    {
        $this->additionalData = $data;
        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userRole = $this->roles->first();

        return [
            // Basic Info
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'photo' => $this->photo,
            'thumbnail' => $this->thumbnail,
            'phone_number' => $this->number,
            'organisation' => $this->organisation,
            'alt_number' => $this->alt_number,

            // Address
            'pincode' => $this->pincode,
            'state' => $this->state,
            'city' => $this->city,

            // Bank Details
            'bank_name' => $this->bank_name,
            'bank_number' => $this->bank_number,
            'bank_ifsc' => $this->bank_ifsc,
            'bank_branch' => $this->bank_branch,
            'bank_micr' => $this->bank_micr,
            'account_holder' => $this->account_holder,

            // Security
            'two_fector_auth' => $this->two_fector_auth,
            'ip_auth' => $this->ip_auth,
            'ip_addresses' => $this->ip_addresses,
            'authentication' => $this->authentication ? 1 : 0,

            // Status & Role
            'status' => $this->status,
            'role' => $userRole ? [
                'id' => $userRole->id,
                'name' => $userRole->name,
            ] : null,

            // Alerts
            'email_alert' => $this->email_alerts,
            'whatsapp_alert' => $this->whatsapp_alerts,
            'sms_alert' => $this->text_alerts,

            // Reporting
            'reporting_user_id' => $this->reportingUser?->id,
            'reporting_user' => $this->reportingUser?->name ?? 'Admin User',

            // Shop
            'shop' => $this->whenLoaded('shop'),

            // Settings
            'qr_length' => $this->qr_length,
            'agent_disc' => $this->agent_disc,
            'agreement_status' => $this->agreement_status,
            'payment_method' => $this->payment_method,

            // Organization Details
            'org_type_of_company' => $this->org_type_of_company,
            'org_office_address' => $this->org_office_address,
            'org_gst_no' => $this->org_gst_no,
            'pan_no' => $this->pan_no,
            'brandName' => $this->brand_name,

            // Signature (from related table)
            'org_signature_type' => $this->organizerSignature?->signature_type,
            'org_name_signatory' => $this->organizerSignature?->signatory_name,
            'org_signatory_image' => $this->organizerSignature?->signature_image,
            'signature_text' => $this->organizerSignature?->signature_text,
            'signature_font' => $this->organizerSignature?->signature_font,
            'signature_font_style' => $this->organizerSignature?->signature_font_style,
            'signing_date' => $this->organizerSignature?->signing_date?->format('Y-m-d'),

            // Events & Tickets (from additional data)
            'events' => $this->additionalData['events'] ?? [],
            'agentTickets' => $this->additionalData['agentTickets'] ?? [],
            'tickets' => $this->additionalData['tickets'] ?? [],
        ];
    }

    /**
     * Customize the response for the resource.
     */
    public function with(Request $request): array
    {
        return [
            'status' => true,
        ];
    }
}
