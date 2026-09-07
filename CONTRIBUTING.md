# অবদান রাখার গাইড
## Contributing Guidelines

আমরা আপনার অবদানকে স্বাগত জানাই! এই গাইড অনুসরণ করুন পুকুর মাছ চাষ প্রকল্পে অবদান রাখতে।

---

## 🎯 অবদানের প্রক্রিয়া

### ধাপ ১: ফর্ক করুন
```bash
# GitHub-এ এই রিপোজিটরি ফর্ক করুন
# আপনার ফর্ক ক্লোন করুন
git clone https://github.com/YOUR_USERNAME/pond-fish.git
cd pond-fish
```

### ধাপ ২: বৈশিষ্ট্য শাখা তৈরি করুন
```bash
# মূল রিপোজিটরি যুক্ত করুন
git remote add upstream https://github.com/toolkit-pro/pond-fish.git

# নতুন শাখা তৈরি করুন
git checkout -b feature/your-feature-name
```

### ধাপ ৩: পরিবর্তন করুন এবং পরীক্ষা করুন
```bash
# আপনার পরিবর্তনগুলি করুন
# PHP সিনট্যাক্স যাচাই করুন
php -l index.php

# স্থানীয়ভাবে পরীক্ষা করুন
php -S localhost:8000
```

### ধাপ ৪: পরিবর্তন কমিট করুন
```bash
# সর্বোত্তম অনুশীলন অনুসরণ করে কমিট করুন
git add .
git commit -m "feat: Add meaningful feature description"
git push origin feature/your-feature-name
```

### ধাপ ৫: পুল রিকোয়েস্ট তৈরি করুন
1. GitHub-এ আপনার ফর্ক খুলুন
2. "Compare & pull request" বাটন ক্লিক করুন
3. পুল রিকোয়েস্টের বর্ণনা লিখুন
4. জমা দিন

---

## 📋 কমিট বার্তার ফরম্যাট

আমরা Conventional Commits অনুসরণ করি:

```
type(scope): subject

body

footer
```

### প্রকার (Type)
- `feat`: নতুন বৈশিষ্ট্য
- `fix`: বাগ ফিক্স
- `docs`: ডকুমেন্টেশন
- `style`: ফরম্যাটিং (সিনট্যাক্স নয়)
- `refactor`: কোড পুনর্গঠন
- `perf`: কর্মক্ষমতা উন্নতি
- `test`: পরীক্ষা যোগ করা
- `chore`: রক্ষণাবেক্ষণ

### উদাহরণ
```
feat(batch): Add batch export to CSV

- Add CSV export functionality
- Include batch summary and growth data
- Support date range filtering

Closes #123
```

---

## 🐛 বাগ রিপোর্ট করা

### ইস্যু তৈরি করার আগে
1. বিদ্যমান ইস্যু সার্চ করুন
2. ডকুমেন্টেশন পড়ুন
3. স্থানীয়ভাবে প্রতিলিপি করার চেষ্টা করুন

### ইস্যু টেমপ্লেট
```markdown
## বর্ণনা
সমস্যার স্পষ্ট বর্ণনা

## পুনরুৎপাদনের ধাপ
1. 
2. 
3. 

## প্রত্যাশিত আচরণ
কি হওয়া উচিত

## বাস্তব আচরণ
আসলে কি হয়েছে

## পরিবেশ
- PHP সংস্করণ: 
- ব্রাউজার: 
- OS: 

## স্ক্রিনশট/ভিডিও
যদি প্রাসঙ্গিক হয়
```

---

## ✨ বৈশিষ্ট্য অনুরোধ করা

### আগে বিবেচনা করুন
- এটি কি প্রকল্পের সুযোগের সাথে সারিবদ্ধ?
- অন্যরা এটি চায় কিনা?
- এটি কি বাস্তবসম্মত?

### অনুরোধ টেমপ্লেট
```markdown
## সমস্যা
আপনি যে সমস্যার সমাধান করতে চান তা বর্ণনা করুন

## সমাধান
আপনার প্রস্তাবিত সমাধান

## বিকল্প
অন্যান্য সম্ভাব্য পদ্ধতি

## প্রসঙ্গ
অতিরিক্ত তথ্য বা স্ক্রিনশট
```

---

## 💻 কোডিং মানদণ্ড

### PHP স্টাইল গাইড

#### নামকরণ সম্মেলন
```php
// ক্লাস: PascalCase
class UserManager {}

// ফাংশন: camelCase
function getUserData() {}

// ধ্রুবক: UPPER_SNAKE_CASE
const MAX_LOGIN_ATTEMPTS = 5;

// ভেরিয়েবল: camelCase
$userData = [];
```

#### ইন্ডেন্টেশন
```php
// 4 স্পেস ব্যবহার করুন
if ($condition) {
    // কোড
}
```

#### ফাংশন ডকুমেন্টেশন
```php
/**
 * ব্যবহারকারীর তথ্য পান
 *
 * @param int $userId ব্যবহারকারীর ID
 * @return array ব্যবহারকারী ডাটা
 * @throws PDOException ডাটাবেস ত্রুটিতে
 */
function getUserData($userId) {
    // বাস্তবায়ন
}
```

#### নিরাপত্তা প্রথম
```php
// ❌ খারাপ - SQL ইনজেকশন ঝুঁকি
$result = $pdo->query("SELECT * FROM users WHERE id = " . $_GET['id']);

// ✅ ভাল - প্রস্তুত স্টেটমেন্ট
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_GET['id']]);
$result = $stmt->fetchAll();

// ✅ ভাল - ইনপুট বৈধতা
$userId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    throw new InvalidArgumentException('Invalid user ID');
}
```

### HTML/CSS মান
```html
<!-- সিমান্টিক HTML ব্যবহার করুন -->
<main>
    <section class="batch-management">
        <h2>ব্যাচ ব্যবস্থাপনা</h2>
    </section>
</main>

<!-- CSS নির্দিষ্টতা সীমা রাখুন -->
/* ❌ খারাপ */
.container .wrapper #main-content div p { color: blue; }

/* ✅ ভাল */
.batch-info p { color: blue; }
```

---

## 🧪 পরীক্ষা চেকলিস্ট

অবদান জমা দেওয়ার আগে:

- [ ] PHP সিনট্যাক্স বৈধ: `php -l index.php`
- [ ] ডাটাবেস মাইগ্রেশন কাজ করে
- [ ] সমস্ত ফর্ম কাজ করে
- [ ] মোবাইলে প্রতিক্রিয়াশীল
- [ ] ব্রাউজার সামঞ্জস্য পরীক্ষিত
- [ ] নিরাপত্তা পরীক্ষা করা হয়েছে
- [ ] ডকুমেন্টেশন আপডেট করা হয়েছে
- [ ] কমিট বার্তা পরিষ্কার এবং বর্ণনামূলক

---

## 📚 প্রকল্প গঠন

```
pond-fish/
├── index.php              # মূল অ্যাপ্লিকেশন
├── .htaccess             # Apache কনফিগ
├── Dockerfile            # Docker সেটআপ
├── render.yaml           # Render ডিপ্লয়মেন্ট
├── LICENSE              # MIT লাইসেন্স
├── README.md            # প্রকল্প ডকুমেন্টেশন
├── CONTRIBUTING.md      # এই ফাইল
└── data/                # ডাটাবেস ডিরেক্টরি
    └── pond.sqlite      # SQLite DB
```

---

## 🔒 নিরাপত্তা প্রতিশ্রুতি

আমরা নিরাপত্তাকে গুরুত্ব সহকারে নিই:

- ✅ PDO প্রস্তুত স্টেটমেন্ট ব্যবহার করুন
- ✅ ইনপুট সর্বদা যাচাই করুন
- ✅ আউটপুট এনকোড করুন
- ✅ CSRF সুরক্ষা যোগ করুন
- ✅ সংবেদনশীল তথ্য লগ করবেন না

### নিরাপত্তা দুর্বলতা রিপোর্ট করা
**জনসাধারণে প্রকাশ করবেন না**। পরিবর্তে:
1. toolkitpro.server@gmail.com এ ইমেল করুন
2. বিবরণ সহ বাগ রিপোর্ট করুন
3. একটি ফিক্স পেতে অপেক্ষা করুন

---

## 📖 আরও তথ্য

- [README.md](README.md) - প্রকল্প ওভারভিউ
- [ডাটাবেস স্ট্রাকচার](README.md#ডাটাবেস-স্ট্রাকচার)
- [API ডকুমেন্টেশন](README.md#-ব্যবহারকারীর-গাইড)

---

## 🙏 ধন্যবাদ!

আপনার অবদানের জন্য ধন্যবাদ! আপনি আমাদের সম্প্রদায়ের একটি গুরুত্বপূর্ণ অংশ।

💬 প্রশ্ন আছে? [GitHub Discussions](https://github.com/toolkit-pro/pond-fish/discussions) এ জিজ্ঞাসা করুন।

**Happy coding! 🐟**
