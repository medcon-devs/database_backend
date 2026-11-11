<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical reference values
    |--------------------------------------------------------------------------
    |
    | The database contains values imported from several historical files.
    | Keep the raw values for auditability, but expose one canonical value from
    | the API and expand canonical filters back to every matching raw alias.
    |
    */
    'countries' => [
        'United Arab Emirates' => [
            'UAE', 'U A E', 'U.A.E', 'Dubai', 'Dubai uae', 'UAE, Dubai',
            'UAE/Dubai', 'Qusais 3. Dubai, United Arab Emirates', 'Abu Dhabi',
            'Abu dahbi', 'Abudhabi', 'UAE/Abu dhabi', 'Al Ain', 'Al Ain, UAE',
            'UAE Al Ain', 'United arab emirates-alain', 'Sharjah', 'Sharjah, UAE',
            'Ajman', 'Fujairah', 'Ras Al Khaimah', 'RAS AL KHAIMAH',
            'Ras-AlKhaimah', 'UAE Ras Al KHAIMAH', 'Umm Al Quwain', 'Aldhfra',
            'Inited arab emirates', 'United arab emirate', 'United Arabia Emirates',
            'United Arabic emirates', 'United Arabs of Emirates',
            'United arac emirates', 'United Arab Emirates (the)',
            'Vereinigte Arabische Emirate',
            'الإمارات', 'الامارات', 'الإمارات العربية المتحدة', 'دبي', 'أبو ظبي', 'ابوظبي',
        ],
        'Saudi Arabia' => [
            'KSA', 'Damam KSA', 'Dammam', 'Jaddah', 'JED', 'Jeddah', 'Riyadh',
            'Taif', 'WZ-Jeddah', 'السعودية', 'المملكة العربية السعودية', 'الرياض', 'جدة', 'الدمام',
        ],
        'Oman' => [
            'Sultanate of Oman', 'Saltanate of oman', 'Muscat', 'Muscat oman',
            'Muscut', 'Oman / Muscat', 'Oman /muscat', 'Oman muscat',
            'Oman-Muscat', 'Oman, Muscat', 'Oman/ Bawshar', 'Oman/ Muscat',
            'Oman/ North Albatinah', 'Oman/Muscat', 'Sohar/ Oman', 'Alburaime',
            'Ghoubra', 'عمان', 'سلطنة عمان', 'مسقط',
        ],
        'Kuwait' => [
            'Kuwait -capital', 'Kuwaite', 'Kwait', 'Kwt', 'Aljahra', 'Salmiya', 'الكويت',
        ],
        'Bahrain' => ['Baharin', 'Bahrain/manama', 'Bahraini', 'Bahrian', 'البحرين', 'المنامة'],
        'Yemen' => ['Sanaa', 'Sanaa/Yemen', 'Thamar yemen', 'Yemen - Ibb', 'اليمن', 'صنعاء'],
        'Iraq' => ['Iraqi', 'العراق'],
        'Syria' => ['Syrian', 'سوريا'],
        'Jordan' => ['Amman', 'الأردن', 'الاردن', 'عمان الأردن', 'عمان الاردن'],
        'Lebanon' => ['Beirut', 'لبنان', 'بيروت'],
        "Côte d'Ivoire" => [
            "CÃ´te d'Ivoire", 'Cote d Ivoire', "Cote d'Ivoire", 'Cote D"Ivoire',
            'Cote dIvoire', 'Ivory Coast',
        ],
        'Democratic Republic of the Congo' => [
            'Congo (the Democratic Republic of the)',
            'Congo, Democratic Republic of the',
            'Congo, The Democratic Republic of the', 'DRC', 'DRC Congo',
        ],
        'Republic of the Congo' => [
            'Congo', 'Congo (the)', 'Congo, Republic of the',
        ],
        'Tanzania' => ['Tanzania, United Republic of'],
        'Sudan' => ['Sudan (the)'],
        'Niger' => ['Niger (the)'],
        'Comoros' => ['Comoros (the)'],
        'Gambia' => ['Gambia (the)', 'The Gambia'],
        'Philippines' => ['Philippines (the)'],
        'South Korea' => ['Korea (the Republic of)', 'Korea, South'],
        'Cabo Verde' => ['Cape Verde'],
        'Micronesia' => ['Micronesia, Federated States of'],
        'Antigua and Barbuda' => ['Antigua & Barbuda'],
        'Bosnia and Herzegovina' => ['Bosnia & Herzegovina'],
        'Saint Helena, Ascension and Tristan da Cunha' => ['St. Helena'],
        'Saint Kitts and Nevis' => ['St. Kitts & Nevis'],
        'Saint Lucia' => ['St. Lucia'],
        'Saint Vincent and the Grenadines' => ['St. Vincent & Grenadines'],
        'São Tomé and Príncipe' => ['Sao Tome and Principe'],
        'Trinidad and Tobago' => ['Trinidad & Tobago'],
        'United Kingdom' => [
            'United Kingdom of Great Britain and Northern Ireland (the)', 'UK',
        ],
        'United States' => [
            'United States of America', 'United States of America (the)',
            'USA', 'U.S.A', 'US',
        ],
        'Russia' => ['Russian Federation'],
        'Türkiye' => ['Turkey'],
        'Czechia' => ['Czech Republic'],
        'Eswatini' => ['Swaziland'],
        'Myanmar' => ['Myanmar (Burma)'],
        'North Macedonia' => ['Macedonia'],
        'Bahamas' => ['The Bahamas'],
        'Cameroon' => ['Cameroun'],
        'India' => ['Indian'],
    ],

    'specialities' => [
        'Other' => [
            'Others', 'Industry', 'Speaker', 'Product Specialist',
            'Health product specialist', 'Healthcare product specialist',
            'Medical Delegate', 'Medical deligate', 'Hospital management',
        ],
        'General Practitioner' => [
            'GP', 'G.p', 'General Practice', 'General Practioner',
            'General physician', 'General physicians', 'general-physicians',
            'General practitioners', 'primary care', 'Phc', 'طبيب عام', 'طب عام', 'ممارس عام',
        ],
        'Internal Medicine' => [
            'Internist', 'IM', 'internal-medicine', 'Internal m.',
            'General Medicine', 'Medicine', 'Hospital Medicine', 'باطنية', 'الباطنية', 'الطب الباطني',
        ],
        'Family Medicine' => ['Family Physician', 'Family medicine', 'fm'],
        'Cardiology' => [
            'Cardiologist', 'Cardio', 'Cardiolofist', 'Cardiac',
            '46/Al Qassimi Hospital - Cardiology Section', 'قلب', 'القلب', 'طبيب قلب', 'أخصائي قلب', 'اخصائي قلب',
        ],
        'Endocrinology' => ['Endocrinologist', 'غدد', 'الغدد الصماء'],
        'Diabetology' => ['Diabetologist', 'Diabetes', 'سكري', 'السكري'],
        'Nephrology' => ['Nephrologist', 'Nephro', 'كلى', 'الكلى'],
        'Neurology' => ['Neurologist', 'أعصاب', 'اعصاب', 'الأعصاب'],
        'Pediatrics' => ['Pediatrician', 'Paediatrics', 'Paediatrician', 'أطفال', 'اطفال', 'طب الأطفال'],
        'Nursing' => [
            'Nurse', 'nurses', 'Registered Nurse', 'Staff Nurse',
        ],
        'Pharmacy' => [
            'Pharmacist', 'pharmacists', 'Clinical pharmacist',
            'ASSISTANT PHARMACIST', 'صيدلي', 'صيدلية', 'الصيدلة',
        ],
        'General Surgery' => ['general-surgeons'],
        'Obstetrics & Gynecology' => [
            'Obstetrics and gynecology', 'Obstetrics-and-gynaecology',
            'Obstetrics and gynaecology',
        ],
        'Radiology' => ['Radiography', 'radiologists'],
        'Emergency Medicine' => ['Emergency medicine', 'Emergency Department'],
        'Critical Care' => ['Intensive Care Unit (ICU)', 'Specialist ICU'],
        'Medical Trainee' => [
            'Medical Students', 'Medical student', 'Pharmacy student',
            'Medical student / University of Sharjah', 'Student',
        ],
        'Nutrition & Dietetics' => [
            'Nutritionist', 'Clinical Dietitian',
            'Clinical Diabetes, Dietetics & Nutrition',
        ],
        'Diabetes Education' => ['Diabetes Educator'],
        'Vascular Medicine' => ['Vascular'],
        'Pulmonology' => ['Pulmonary Medicine'],
        'Pathology & Laboratory Medicine' => ['Pathology & Lab Medicine'],
        'Hematology' => ['Hematologist'],
    ],

    /* Natural-language terms used only to understand smart-search requests. */
    'search_speciality_aliases' => [
        'Cardiology' => ['heart doctor', 'heart specialist', 'cardiologist', 'heart', 'قلب', 'طبيب قلب'],
        'Dermatology' => ['skin doctor', 'skin specialist', 'dermatologist', 'dermatology', 'جلدية', 'طبيب جلدية'],
        'General Practitioner' => ['general doctor', 'family doctor', 'gp doctor', 'general practitioner', 'طبيب عام'],
        'Internal Medicine' => ['internist', 'internal medicine doctor', 'باطنية', 'طبيب باطنية'],
        'Pediatrics' => ['children doctor', 'child doctor', 'pediatrician', 'paediatrician', 'طبيب أطفال'],
        'Obstetrics & Gynecology' => ['gynecologist', 'gynaecologist', 'obgyn', 'women doctor', 'نسائية', 'طبيبة نسائية'],
        'Neurology' => ['neurologist', 'brain doctor', 'nerve doctor', 'طبيب أعصاب'],
        'Nephrology' => ['nephrologist', 'kidney doctor', 'طبيب كلى'],
        'Endocrinology' => ['endocrinologist', 'hormone doctor', 'طبيب غدد'],
        'Diabetology' => ['diabetes doctor', 'diabetologist', 'طبيب سكري'],
        'Oncology' => ['oncologist', 'cancer doctor', 'طبيب أورام'],
        'Orthopedics' => ['orthopedic doctor', 'orthopaedic doctor', 'bone doctor', 'طبيب عظام'],
        'Ophthalmology' => ['ophthalmologist', 'eye doctor', 'طبيب عيون'],
        'ENT' => ['ent doctor', 'ear nose throat', 'otolaryngologist', 'أنف أذن حنجرة'],
        'Psychiatry' => ['psychiatrist', 'mental health doctor', 'طبيب نفسي'],
        'Dentistry' => ['dentist', 'dental doctor', 'طبيب أسنان'],
        'Urology' => ['urologist', 'urinary doctor', 'طبيب مسالك'],
        'Gastroenterology' => ['gastroenterologist', 'stomach doctor', 'digestive doctor', 'طبيب جهاز هضمي'],
        'Pulmonology' => ['pulmonologist', 'lung doctor', 'chest doctor', 'طبيب صدرية'],
        'Rheumatology' => ['rheumatologist', 'arthritis doctor', 'طبيب روماتيزم'],
        'General Surgery' => ['general surgeon', 'surgeon', 'جراح'],
        'Pharmacy' => ['pharmacist', 'صيدلي'],
        'Nursing' => ['nurse', 'ممرض', 'ممرضة'],
    ],

    /*
     * Ordered rules for historical speciality text not covered by an exact
     * alias above. The service applies the first matching rule. Anything that
     * still cannot be classified is returned as Other, never as a new option.
     */
    'speciality_patterns' => [
        ['canonical' => 'Medical Trainee', 'pattern' => '/\b(student|undergraduate|resident|intern|graduate|attache)\b/'],
        ['canonical' => 'Nursing', 'pattern' => '/\b(nurse|nursing|scn)\b/'],
        ['canonical' => 'Pharmacy', 'pattern' => '/\b(pharm|pharmacist|pharmacy|pharmaceutical)\b/'],
        ['canonical' => 'Cardiothoracic Surgery', 'pattern' => '/\b(cardio thoracic|cardiothoracic|cardiac surgery)\b/'],
        ['canonical' => 'Neurosurgery', 'pattern' => '/\bneurosurg/'],
        ['canonical' => 'Colorectal Surgery', 'pattern' => '/\b(colorectal|proctolog)/'],
        ['canonical' => 'Plastic Surgery', 'pattern' => '/\bplastic surgery\b/'],
        ['canonical' => 'General Surgery', 'pattern' => '/\b(general surg|surgeon|surgery)/'],
        ['canonical' => 'Obstetrics & Gynecology', 'pattern' => '/\b(obst|obg|obsgyn|gyn|gyne|gynaec|ob and gyn)/'],
        ['canonical' => 'Cardiology', 'pattern' => '/\b(cardio|cardiac|cardiol|cardiovascular|ccu|card)/'],
        ['canonical' => 'Endocrinology', 'pattern' => '/\b(endo|endocrin)/'],
        ['canonical' => 'Diabetology', 'pattern' => '/\b(diabet|disbet)/'],
        ['canonical' => 'Nephrology', 'pattern' => '/\b(neph|neohro|dialysis)\b/'],
        ['canonical' => 'Neurology', 'pattern' => '/\b(neuro|neorolog)/'],
        ['canonical' => 'Pediatrics', 'pattern' => '/\b(pediatr|paediatr)\b/'],
        ['canonical' => 'Gastroenterology', 'pattern' => '/\b(gastro|gastrointestinal)\b/'],
        ['canonical' => 'Pulmonology', 'pattern' => '/\b(pulmon|chest)\b/'],
        ['canonical' => 'Family Medicine', 'pattern' => '/\b(family|fm)\b/'],
        ['canonical' => 'General Practitioner', 'pattern' => '/\b(gp|g p|general pract|general physician|medical practitioner|medecin|medecine generale)/'],
        ['canonical' => 'Internal Medicine', 'pattern' => '/\b(internal|interenl|enternist|internist|i m|hospital medicine)\b/'],
        ['canonical' => 'Emergency Medicine', 'pattern' => '/\b(emergency|accident and emergency|first aid|er physician|ed physician)\b/'],
        ['canonical' => 'Critical Care', 'pattern' => '/\b(critical care|intensive care|icu)\b/'],
        ['canonical' => 'Anesthesiology', 'pattern' => '/\b(anesth|anaesth|pain medicine)/'],
        ['canonical' => 'Radiology', 'pattern' => '/\b(radiol|radiograph)/'],
        ['canonical' => 'Nutrition & Dietetics', 'pattern' => '/\b(nutrition|dietitian|dietetics)\b/'],
        ['canonical' => 'Pathology & Laboratory Medicine', 'pattern' => '/\b(patholog|laboratory|lab|phlebotom)\b/'],
        ['canonical' => 'Hematology', 'pattern' => '/\bhematolog/'],
        ['canonical' => 'Oncology', 'pattern' => '/\b(oncolog)/'],
        ['canonical' => 'Rheumatology', 'pattern' => '/\brheumatolog/'],
        ['canonical' => 'Public Health', 'pattern' => '/\b(public health|health education|health promotion)\b/'],
        ['canonical' => 'Preventive & Occupational Medicine', 'pattern' => '/\b(preventive|occupational health|medical fitness)\b/'],
        ['canonical' => 'Dentistry', 'pattern' => '/\b(dentist|dental|endodont)/'],
        ['canonical' => 'Orthopedics', 'pattern' => '/\b(orthopedic|orthopaedic)\b/'],
        ['canonical' => 'Urology', 'pattern' => '/\burolog/'],
        ['canonical' => 'Physiotherapy & Rehabilitation', 'pattern' => '/\b(physiotherap|rehabilitation)\b/'],
        ['canonical' => 'Psychiatry', 'pattern' => '/\bpsychiatr/'],
        ['canonical' => 'Geriatrics', 'pattern' => '/\bgeriatric/'],
        ['canonical' => 'ENT', 'pattern' => '/\b(ent|entrisit)\b/'],
        ['canonical' => 'Speech Therapy', 'pattern' => '/\bspeech therap/'],
        ['canonical' => 'Clinical Officer', 'pattern' => '/\bclinical officer\b/'],
        ['canonical' => 'General Practitioner', 'pattern' => '/\b(physician|doctor|docter|dr|medical)\b/'],
    ],

    'empty_values' => [
        '', 'null', 'n/a', 'na', 'nil', '-', '-1', '0', 'country',
        'speciality', '____', '_____', '______',
    ],

    /* Words that describe the request rather than the contact identity. */
    'search_noise_phrases' => [
        'information technology', 'same last name', 'same first name',
        'looking for', 'look for', 'search for', 'find me', 'show me',
        'medical professional', 'healthcare professional',
        'find', 'search', 'show', 'give', 'get', 'need', 'want', 'looking',
        'look', 'for', 'doctor', 'doctors', 'dr', 'specialist', 'consultant',
        'named', 'name', 'called', 'with', 'same', 'last', 'first', 'surname',
        'from', 'in', 'at', 'the', 'a', 'an', 'please', 'me', 'who', 'works',
        'working', 'based', 'located', 'around', 'about', 'maybe', 'possibly',
        'probably', 'department', 'team', 'employee', 'staff', 'manager',
        'management', 'sales', 'marketing', 'it', 'and', 'or', 'i', 'we',
        'my', 'our', 'someone', 'person', 'contact', 'contacts', 'all', 'where',
        'ابحث عن', 'أبحث عن', 'اعثر على', 'دور على', 'أريد', 'اريد',
        'بدي', 'أحتاج', 'احتاج', 'اظهر', 'أظهر', 'ابحث', 'أبحث', 'اعثر',
        'دكتور', 'دكتورة', 'الدكتور', 'الدكتورة', 'طبيب', 'طبيبة',
        'اسمه', 'اسمها', 'اسمو', 'الاسم', 'تخصصه', 'تخصصها',
        'اختصاصه', 'اختصاصها', 'عن', 'لي', 'في', 'من', 'داخل', 'موجود',
        'يعمل', 'تعمل', 'ممكن', 'ربما', 'و', 'أو', 'او',
    ],
];
