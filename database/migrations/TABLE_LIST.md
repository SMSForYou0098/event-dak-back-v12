# Complete Table List

## All 125 Tables in Your Database

### Access & Security (7)
1. access_areas
2. blocked_ip_addresses
3. permissions
4. roles
5. role_has_permissions
6. model_has_permissions
7. model_has_roles

### Accreditation (2)
8. accreditation_bookings
9. accreditation_master_bookings

### Agents (4)
10. agents
11. agent_events
12. agent_masters
13. agreements

### Amusement (5)
14. amusement_agent_bookings
15. amusement_agent_master_bookings
16. amusement_bookings
17. amusement_master_bookings
18. amusement_pending_bookings
19. amusement_pending_master_bookings
20. amusement_pos_bookings

### Artists & Announcements (2)
21. announcements
22. artists

### Attendees & Balance (2)
23. attndies
24. balances

### Blog & Content (4)
25. banners
26. blogs
27. blog_comments
28. pages

### Bookings (8)
29. bookings
30. booking_taxes
31. card_bookings
32. complimentary_bookings
33. corporate_bookings
34. master_bookings
35. pendding_bookings
36. pendding_bookings_masters
37. pos_bookings
38. sponsor_bookings
39. sponsor_master_bookings

### Categories & Fields (4)
40. add_cates
41. categories
42. cat_layouts
43. catrgoty_has__fields
44. custom_fields

### Communication (6)
45. contact_us
46. email_configs
47. email_templates
48. queries
49. sms_configs
50. sms_custom_apis
51. sms_templates
52. fcm_tokens

### Corporate (2)
53. corporate_users
54. commisions

### Events (11)
55. events
56. event_att_fields
57. event_controls
58. event_galleries
59. event_gates
60. event_has_layouts
61. event_seats
62. event_seat_statuses
63. exhibition_bookings
64. highlight_events
65. successful_events

### Failed Jobs & System (3)
66. failed_jobs
67. jobs
68. system_uploads
69. system_variables

### FAQ & Help (1)
70. faqs

### Footer & Navigation (4)
71. footer_groups
72. footer_menus
73. menu_groups
74. navigation_menus

### Layout & Seating (15)
75. layouts
76. l_rows
77. l_seats
78. l_sections
79. l_stages
80. l_tiers
81. l_venues
82. l_zones
83. seat_configs

### Live Tracking (2)
84. live_user_counts
85. login_histories

### OAuth (5)
86. oauth_access_tokens
87. oauth_auth_codes
88. oauth_clients
89. oauth_personal_access_clients
90. oauth_refresh_tokens

### Organization (2)
91. organization_types
92. promote_orgs

### Password & Auth (2)
93. password_reset_tokens
94. personal_access_tokens

### Payment Gateways (7)
95. cashfree_configs
96. easebuzzs
97. instamojos
98. intramojo_configs
99. pay_pals
100. payment_logs
101. paytms
102. phone_pes
103. razorpays
104. stripes

### Promo Codes (1)
105. promo_codes

### Scanning (3)
106. scan_histories
107. scanner_gates
108. notifiction_images

### SEO & Settings (2)
109. seo_configs
110. settings

### Shops (1)
111. shops

### Short URLs (1)
112. short_urls

### Social Media (1)
113. social_media

### System Tables (2)
114. migrations
115. websockets_statistics_entries

### Taxes (1)
116. taxes

### Tickets (3)
117. tickets
118. ticket_histories
119. user_tickets

### Users (2)
120. users
121. user_infos

### Venues (1)
122. venues

### Welcome & Popup (1)
123. welcome_pop_ups

### WhatsApp (2)
124. whatsapp_apis
125. whatsapp_configurations

---

## Tables by Category for Quick Reference

### Most Important Tables (Must Review)
- ✅ **users** - Core user table
- ✅ **events** - Event management
- ✅ **bookings** - Main booking table
- ✅ **tickets** - Ticket types
- ✅ **venues** - Venue information
- ✅ **layouts** - Seating layouts
- ✅ **payments_logs** - Payment tracking

### Tables with Foreign Keys (Check Order)
These tables reference other tables and should be migrated after their dependencies:
- agent_events → users, events
- bookings → users, events, tickets
- event_has_layouts → events, layouts
- event_seats → events
- ticket_histories → tickets
- user_tickets → users, tickets

### Tables with JSON Columns
- accreditation_bookings (access_area)
- Consider converting to `jsonb()` for better PostgreSQL performance

### Tables with ENUM Columns
Review these for your specific business logic:
- (Check individual migration files for enum values)

## File Size Summary
Total migration files: 125
Average file size: ~1-3 KB
Total size: ~177 KB
