# LearnAlytics - Google Classroom Clone

A full-featured learning management system (LMS) with Google Classroom-like functionality.

---

## Quick Start

### 1. Run Database Setup
```
php setup.php
```

### 2. Apply Database Migrations
```
php run_migration.php
```
Run this after setup and on existing installations to add course catalog fields, department constraints, instructor-managed course grades, assignment review/annotation storage, semester relationships, and academic-year management fields, including direct academic-year assignment for classes.

### Quiz Builder

After applying the quiz-system enhancement migration, run:
```
php run_quiz_builder_migration.php
```
This adds question types, per-question scoring, quiz availability and feedback settings, manual-grading choices for written questions, and submission grading fields used by the instructor quiz builder and student quiz portal.

Instructors can create draft quizzes from **Quizzes → Create Quiz**, edit and reorder multiple-choice, checkbox, short-answer, paragraph, true/false, and dropdown questions, set schedules and attempts, preview student view, and publish to enrolled students. Each question must have a valid question, choices and answer key, or explicitly be set for manual grading when it is a written-response question. Objective and keyed written questions are scored automatically; manually graded written responses remain pending for instructor review.

### 3. Seed Demo Data (Optional)
```
php seed.php
```

### 4. Login Credentials
| Role | Email | Password |
|------|-------|----------|
| Admin | admin@edulearn.com | admin123 |
| Instructor | john@edulearn.com | john123 |
| Student | jane@edulearn.com | jane123 |

---

## Features Guide

### 🎓 Programs & Sections (Course Management)

**Admin creates:**
1. **Programs** → e.g., "Computer Science", "Business"
2. **Academic Years** → e.g., "2025-2026" with start/end dates
3. **Sections** → Program + Year combination → e.g., "CS Section A"

**Student enrolls:**
- Visit `programs/view.php`
- Browse programs and sections
- Click "Enroll" to join a section
- View all subjects in that section

---

### 📚 Subjects (Classes)

**Teacher creates:**
- Title, description
- Associate with Program/Section (optional)
- Auto-generates 6-character **Class Code** (e.g., `ABC123`)

**Student joins:**
1. **Option A**: Go to `subjects/join.php` → Enter class code
2. **Option B**: Enroll in Program → Access subjects from section page

---

### 📝 Classwork Features

| Feature | Access | Description |
|---------|--------|-------------|
| **Lessons** | `lessons/` | Video lessons with content |
| **Assignments** | `assignments/` | Homework with due dates |
| **Quizzes** | `quizzes/` | Online assessments |
| **Grades** | `grades/index.php` | View scores & feedback |

---



## File Structure

```
LearnAlytics/
├── programs/
│   ├── index.php      # Admin: manage programs/sections
│   ├── view.php       # Student: browse & enroll
│   └── section.php    # Section details + subjects
├── subjects/
│   ├── index.php      # Browse all subjects
│   ├── view.php       # Subject stream (lessons, assignments...)
│   ├── create.php     # Teacher: create subject
│   ├── join.php       # Student: join by class code
│   └── get_sections.php # AJAX: load sections
 
├── lessons/, assignments/, quizzes/, grades/ # Content pages
├── auth/              # Login, register, logout
├── admin/             # Admin panel
├── config/database.php
├── schema.sql         # Database schema
└── setup.php          # Database setup script
```

---

## Database Schema

### Core Tables

| Table | Description |
|-------|-------------|
| `users` | Users (admin, instructor, student) |
| `programs` | Academic programs |
| `academic_years` | School years |
| `sections` | Program + Year combinations |
| `subjects` | Subjects with class_code |
| `enrollments` | Student enrollments |
| `lessons` | Video lessons |
| `assignments` | Homework |
| `quizzes` | Assessments |
| `submissions` | Student submissions |
 

---

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `subjects/get_sections.php?program_id=X` | GET | Get sections for a program |
| `subjects/join.php` | POST | Join class by code |
| `programs/view.php` | POST | Enroll in section |

---

## User Roles

| Role | Permissions |
|------|-------------|
| **Admin** | Manage users, programs, sections, all subjects |
| **Instructor** | Create subjects, lessons, assignments, quizzes |
| **Student** | Enroll via code or program, submit work, view grades |

---

## Class Code System

- **Format**: 6 characters (letters + numbers)
- **Generation**: Auto-generated on subject creation
- **Uniqueness**: Unique index prevents duplicates
- **Usage**: Students enter code at `subjects/join.php`

---

 

## Support

For issues or questions, check:
1. Database tables exist: `php setup.php`
2. User role is correct
3. Subject has class code for student enrollment