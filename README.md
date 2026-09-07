# 🐟 পুকুর মাছ চাষ প্রকল্প
## Pond Fish Farming Management System

[![PHP](https://img.shields.io/badge/PHP-8.1+-blue)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/Database-SQLite-green)](https://www.sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-yellow)](#লাইসেন্স)
[![Version](https://img.shields.io/badge/Version-6.0.0-orange)](#ভার্সন)

সম্পূর্ণ পুকুর মাছ চাষ ব্যবস্থাপনা সফটওয়্যার যা বাংলা ভাষায় ডিজাইন করা হয়েছে। এটি একটি একক PHP ফাইল অ্যাপ্লিকেশন যা মোবাইল-ফার্স্ট ডিজাইনে তৈরি এবং SQLite ডাটাবেস ব্যবহার করে।

**Complete Pond Fish Farming Management System with Accounting, Growth Analysis, and Live Analytics**

---

## ✨ মূল বৈশিষ্ট্য

### 🐠 **ব্যাচ ব্যবস্থাপনা**
- একাধিক মাছের ব্যাচ ট্র্যাকিং
- প্রাথমিক এবং বর্তমান ওজন ট্র্যাকিং
- মাছের সংখ্যা এবং মৃত্যুর রেকর্ড
- ব্যাচ স্ট্যাটাস পরিচালনা

### 📈 **বৃদ্ধি বিশ্লেষণ**
- ৭ দিনের ব্যবধানে স্ন্যাপশট ট্র্যাকিং
- বৃদ্ধির শতাংশ এবং টিকে থাকার হার
- খাদ্য খাওয়ানোর হার গণনা
- সাপ্তাহিক বিশ্লেষণ

### 💰 **সম্পূর্ণ হিসাব ব্যবস্থা**
- খাদ্য খরচ ট্র্যাকিং
- স্বাস্থ্য খরচ রেকর্ডিং
- অন্যান্য খরচ (শ্রম, যন্ত্রপাতি)
- লাভ-ক্ষতি প্রতিবেদন
- মুনাফার হার গণনা

### 🏥 **স্বাস্থ্য পর্যবেক্ষণ**
- ওষুধ ব্যবহারের রেকর্ড
- চুন এবং লবণ প্রয়োগ লগ
- জল মানের তথ্য
- খরচ ট্র্যাকিং

### 🍽️ **খাদ্য ব্যবস্থাপনা**
- দৈনিক খাদ্য লগ
- খাদ্য খরচ রেকর্ডিং
- প্রতি ব্যাচে খাদ্য ট্র্যাকিং

### 📊 **লাইভ অ্যানালিটিক্স**
- রিয়েল-টাইম ড্যাশবোর্ড
- গ্রাফ এবং চার্ট ভিজুয়ালাইজেশন
- প্রতি ৬০ সেকেন্ডে অটো-রিফ্রেশ
- KPI কার্ড ডিসপ্লে

### 🔐 **নিরাপত্তা বৈশিষ্ট্য**
- পিন-ভিত্তিক প্রমাণীকরণ
- ব্রুট ফোর্স সুরক্ষা (5 প্রচেষ্টার পর 5 মিনিট লক)
- CSRF সুরক্ষা সব ফর্মে
- সেশন টাইমআউট (২ ঘণ্টা)
- অডিট লগিং
- SQL ইনজেকশন সুরক্ষা

### 📱 **মোবাইল-ফার্স্ট ডিজাইন**
- প্রতিক্রিয়াশীল লেআউট
- মোবাইলে নীচের নেভিগেশন
- ডেস্কটপে শীর্ষ নেভিগেশন
- টাচ-বান্ধব বাটন এবং ফর্ম

### 🇧🇩 **সম্পূর্ণ বাংলা ইন্টারফেস**
- Noto Sans Bengali ফন্ট
- সব পাঠ্য বাংলায়
- বাংলা তারিখ ফরম্যাট

---

## 🚀 দ্রুত শুরু

### প্রয়োজনীয়তা
- PHP 8.1 বা উচ্চতর
- SQLite 3
- Apache ওয়েব সার্ভার (বা অন্য কোনো PHP-সমর্থিত সার্ভার)

### ডিফল্ট লগইন
```
পিন: 3894
```

### স্থানীয় ইনস্টলেশন

#### ১. ফাইল ডাউনলোড করুন
```bash
git clone https://github.com/toolkit-pro/pond-fish.git
cd pond-fish
```

#### ২. ডাটা ডিরেক্টরি তৈরি করুন
```bash
mkdir -p data
chmod 755 data
```

#### ৩. PHP অন্তর্নির্মিত সার্ভার ব্যবহার করুন (উন্নয়নের জন্য)
```bash
php -S localhost:8000
```

তারপর ব্রাউজারে খুলুন: `http://localhost:8000`

#### ৪. Apache ব্যবহার করুন (উৎপাদনের জন্য)
```bash
# Apache-এর জন্য ফাইলগুলি সঠিক স্থানে কপি করুন
sudo cp index.php /var/www/html/
sudo cp .htaccess /var/www/html/
sudo mkdir -p /var/www/html/data
sudo chmod 755 /var/www/html/data
sudo chown www-data:www-data /var/www/html/data
```

---

## 🌐 Render.com-এ ডিপ্লয়মেন্ট

### পদক্ষেপ ১: GitHub রিপোজিটরি সংযুক্ত করুন
1. [Render.com](https://render.com) এ যান
2. আপনার অ্যাকাউন্টে লগইন করুন
3. নতুন "Web Service" তৈরি করুন
4. GitHub রিপোজিটরি নির্বাচন করুন: `toolkit-pro/pond-fish`

### পদক্ষেপ ২: সেবা কনফিগার করুন
| সেটিং | মূল্য |
|------|-------|
| **নাম** | pond-fish |
| **Runtime** | Docker |
| **অঞ্চল** | Singapore |
| **পরিকল্পনা** | Free |
| **প্রাথমিক আদেশ** | (খালি রাখুন) |
| **বিল্ড কমান্ড** | (খালি রাখুন) |

### পদক্ষেপ ৩: পার্সিস্টেন্ট ডিস্ক যোগ করুন
1. সার্ভিস তৈরির পরে "Environment" ট্যাব খুলুন
2. "Disks" বিভাগে যান
3. নতুন ডিস্ক যোগ করুন:
   - **Mount Path**: `/var/www/html/data`
   - **সাইজ**: 1 GB

### পদক্ষেপ ৪: স্থাপনা করুন
1. "Create Web Service" বোতাম ক্লিক করুন
2. ডিপ্লয়মেন্ট শুরু হবে
3. সম্পন্ন হলে আপনার অ্যাপ্লিকেশনের URL পাবেন

---

## 📊 ডাটাবেস স্ট্রাকচার

### সেটিংস টেবিল
```sql
settings (
  id INTEGER PRIMARY KEY,
  project_name TEXT,
  pond_depth REAL,
  market_price_per_kg REAL,
  notes TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### ব্যাচ টেবিল
```sql
batches (
  id INTEGER PRIMARY KEY,
  batch_no TEXT UNIQUE,
  fish_name TEXT,
  release_date DATE,
  initial_weight REAL,
  initial_count INTEGER,
  initial_avg_weight REAL,
  initial_cost REAL,
  death_weight REAL,
  death_count INTEGER,
  current_count INTEGER,
  current_weight REAL,
  status TEXT,
  notes TEXT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
)
```

### বৃদ্ধি স্ন্যাপশট টেবিল
```sql
growth_snapshots (
  id INTEGER PRIMARY KEY,
  batch_id INTEGER,
  snapshot_date DATE,
  live_count INTEGER,
  live_weight REAL,
  avg_weight REAL,
  growth_weight REAL,
  growth_percent REAL,
  survival_percent REAL,
  feed_rate_percent REAL,
  daily_feed_kg REAL,
  weekly_feed_kg REAL,
  created_at TIMESTAMP
)
```

### খাদ্য লগ টেবিল
```sql
feed_logs (
  id INTEGER PRIMARY KEY,
  log_date DATE,
  batch_id INTEGER,
  feed_kg REAL,
  feed_cost REAL,
  notes TEXT,
  created_at TIMESTAMP
)
```

### স্বাস্থ্য লগ টেবিল
```sql
health_logs (
  id INTEGER PRIMARY KEY,
  log_date DATE,
  batch_id INTEGER,
  log_type TEXT,
  amount REAL,
  unit TEXT,
  cost REAL,
  details TEXT,
  created_at TIMESTAMP
)
```

### খরচ লগ টেবিল
```sql
expense_logs (
  id INTEGER PRIMARY KEY,
  expense_date DATE,
  batch_id INTEGER,
  expense_type TEXT,
  amount REAL,
  notes TEXT,
  created_at TIMESTAMP
)
```

### অডিট লগ টেবিল
```sql
audit_logs (
  id INTEGER PRIMARY KEY,
  action TEXT,
  entity TEXT,
  entity_id INTEGER,
  details TEXT,
  ip TEXT,
  created_at TIMESTAMP
)
```

---

## ⏰ মধ্যরাতের ক্রন জব সেটআপ

স্বয়ংক্রিয়ভাবে মধ্যরাতে আপডেট চালাতে:

### Linux/Mac-এ Crontab ব্যবহার করুন
```bash
crontab -e
```

এই লাইন যোগ করুন:
```bash
0 0 * * * php /path/to/pond-fish/index.php --cron
```

### ডিজিটাল অশ্ব (Render) এ
পরিবেশ পরিবর্তন করুন বা সাধারণ ক্রন সেবা ব্যবহার করুন।

---

## 📋 নমুনা ডাটা

অ্যাপ্লিকেশন স্বয়ংক্রিয়ভাবে এই নমুনা ব্যাচগুলি তৈরি করে:

| ব্যাচ নো. | মাছের ধরন | ছাড়ের তারিখ | প্রাথমিক ওজন | প্রাথমিক সংখ্যা |
|---------|---------|---------|---------|---------|
| B-001 | ছোট পোনা | ২০২৬-০৭-০৮ | ১০ কেজি | ১,২০০-১,৫০০ |
| B-002 | মাঝারি পোনা | ২০২৬-০৭-২২ | ২৫ কেজি | ২৮০ |
| B-003 | কাতল | ২০২৬-০৮-২৪ | ৯ কেজি | ৬৪ |
| B-004 | ব্রিগেড/লাটকাপ | ২০२६-०८-३१ | ৫.৫ কেজি | ৫৬ |

---

## 📱 মোবাইল বৈশিষ্ট্য

- **নীচের নেভিগেশন**: সহজ অ্যাক্সেসের জন্য মোবাইলে দ্রুত নেভিগেশন
- **প্রতিক্রিয়াশীল ডিজাইন**: সব স্ক্রিন সাইজে নিখুঁত দেখায়
- **অফলাইন সমর্থন**: সীমিত অফলাইন কার্যকারিতা (ভবিষ্যত সংস্করণে)

---

## 🎨 রঙের স্কিম

| রঙ | ব্যবহার | হেক্স কোড |
|-----|--------|---------|
| প্রাথমিক | নেভিগেশন, বাটন | `#0f766e` |
| অ্যাক্সেন্ট | হোভার স্টেট | `#14b8a6` |
| বিপদ | বিপদজনক অ্যাকশন | `#dc2626` |
| সাফল্য | সফল বার্তা | `#059669` |

---

## 📄 ফাইল স্ট্রাকচার

```
pond-fish/
├── index.php              # মূল অ্যাপ্লিকেশন ফাইল
├── Dockerfile            # Docker কনফিগারেশন
├── README.md             # এই ফাইল
├── .gitignore           # Git উপেক্ষা নিয়ম
├── .htaccess            # Apache সিকিউরিটি
├── render.yaml          # Render.com স্থাপনা
├── .github/
│   └── workflows/
│       └── deploy.yml   # GitHub Actions CI/CD
└── data/
    ├── .gitkeep         # ডিরেক্টরি প্লেসহোল্ডার
    └── pond.sqlite      # SQLite ডাটাবেস (স্বয়ংক্রিয়)
```

---

## 🔑 ব্যবহারকারীর গাইড

### ড্যাশবোর্ড পৃষ্ঠা
- সক্রিয় ব্যাচের সংখ্যা
- মোট মাছের সংখ্যা এবং ওজন
- মোট খরচ এবং আয়
- ব্যাচ অনুযায়ী বৃদ্ধির চার্ট

### ব্যাচ ব্যবস্থাপনা
- নতুন ব্যাচ যোগ করুন
- বিদ্যমান ব্যাচ সম্পাদনা করুন
- ব্যাচ অপসারণ করুন

### বৃদ্ধি বিশ্লেষণ
- ৭ দিনের স্ন্যাপশট দেখুন
- বৃদ্ধির হার ট্র্যাক করুন
- টিকে থাকার প্রতিশত বিশ্লেষণ করুন

### হিসাব প্রতিবেদন
- মোট খরচ দেখুন
- মোট আয় গণনা করুন
- লাভ/ক্ষতি নির্ধারণ করুন
- মুনাফার হার দেখুন

---

## 🛡️ নিরাপত্তা

### এনক্রিপশন
- সমস্ত পাসওয়ার্ড সুরক্ষিত এবং হ্যাশড
- HTTPS-এ নিরাপদ কুকি
- HTTPOnly ফ্ল্যাগ সক্ষম

### ইনপুট যাচাইকরণ
- HTML এন্টিটি এনকোডিং
- PDO প্রস্তুত স্টেটমেন্ট
- CSRF টোকেন যাচাইকরণ

### রেট লিমিটিং
- লগইন প্রচেষ্টা সীমা (5 প্রচেষ্টা)
- স্বয়ংক্রিয় লকআউট (5 মিনিট)

---

## 🐛 ত্রুটি সমস্যা সমাধান

### ডাটাবেস সমস্যা
```bash
# ডাটা ডিরেক্টরি অনুমতি পরীক্ষা করুন
ls -la data/

# মালিক পরিবর্তন করুন (Apache ব্যবহার করলে)
sudo chown www-data:www-data data/
```

### সাদা পৃষ্ঠা ত্রুটি
```bash
# PHP ত্রুটি লগ পরীক্ষা করুন
tail -f /var/log/php.log

# SQLite এক্সটেনশন যাচাই করুন
php -m | grep pdo_sqlite
```

### অনুমতি ত্রুটি
```bash
# ডাটা ডিরেক্টরি পুনরায় তৈরি করুন
rm -rf data/
mkdir -p data
chmod 755 data
```

---

## 📞 সহায়তা এবং যোগাযোগ

### সমস্যা রিপোর্ট করুন
GitHub Issues পৃষ্ঠায় নতুন সমস্যা তৈরি করুন:
[Issues Page](https://github.com/toolkit-pro/pond-fish/issues)

### বৈশিষ্ট্যের অনুরোধ
আপনার পরামর্শ এবং বৈশিষ্ট্যের অনুরোধ আমাদের কাছে পাঠান।

### যোগাযোগ করুন
- Email: toolkitpro.server@gmail.com
- GitHub: [@toolkit-pro](https://github.com/toolkit-pro)

---

## 🤝 অবদান রাখা

আমরা অবদানের স্বাগত জানাই! এই পদক্ষেপগুলি অনুসরণ করুন:

1. রিপোজিটরি ফর্ক করুন
2. বৈশিষ্ট্য শাখা তৈরি করুন (`git checkout -b feature/AmazingFeature`)
3. আপনার পরিবর্তন কমিট করুন (`git commit -m 'Add some AmazingFeature'`)
4. শাখায় পুশ করুন (`git push origin feature/AmazingFeature`)
5. পুল রিকোয়েস্ট খুলুন

---

## 📜 লাইসেন্স

এই প্রকল্প MIT লাইসেন্সের অধীন - বিবরণের জন্য [LICENSE](LICENSE) ফাইল দেখুন।

---

## 🙏 স্বীকৃতি

- PHP এবং SQLite সম্প্রদায়ের কাছে ধন্যবাদ
- Render.com-এর বিনামূল্যে হোস্টিং প্ল্যাটফর্মের জন্য
- সব অবদানকারী এবং ব্যবহারকারীদের কাছে ধন্যবাদ

---

## 📝 পরিবর্তন লগ

### সংস্করণ 6.0.0 (2026-09-07)
- ✨ প্রাথমিক রিলিজ
- 🐠 সম্পূর্ণ ব্যাচ ব্যবস্থাপনা
- 💰 হিসাব সিস্টেম
- 📊 লাইভ বিশ্লেষণ ড্যাশবোর্ড
- 🔐 নিরাপত্তা বৈশিষ্ট্য
- 📱 মোবাইল-ফার্স্ট ডিজাইন
- 🇧🇩 সম্পূর্ণ বাংলা ইন্টারফেস

---

**সর্বশেষ আপডেট**: ২০২৬-০৯-০৭

**রক্ষণাবেক্ষণকারী**: [ToolKit Pro](https://github.com/toolkit-pro)

🐟 **Happy Farming!** 🌾
