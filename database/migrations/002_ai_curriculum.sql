-- =============================================
-- AI CURRICULUM TABLES
-- For AI-taught courses with structured curriculum
-- Version: 1.0.0
-- =============================================

-- AI Courses (separate from video courses)
CREATE TABLE IF NOT EXISTS ai_courses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    short_description VARCHAR(500) NULL,
    thumbnail VARCHAR(255) NULL,
    category_id INT NULL,
    level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner',
    language VARCHAR(50) DEFAULT 'English',
    total_lessons INT DEFAULT 0,
    estimated_hours DECIMAL(5,2) DEFAULT 0,
    is_free BOOLEAN DEFAULT FALSE,
    is_featured BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT FALSE,
    tags JSON NULL,
    prerequisites JSON NULL COMMENT 'Recommended prior knowledge',
    learning_outcomes JSON NULL COMMENT 'What students will learn',
    total_enrollments INT DEFAULT 0,
    rating DECIMAL(2,1) DEFAULT 0,
    total_reviews INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_slug (slug),
    INDEX idx_published (is_published),
    INDEX idx_featured (is_featured),
    INDEX idx_category (category_id)
) ENGINE=InnoDB;

-- AI Course Modules
CREATE TABLE IF NOT EXISTS ai_modules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    sort_order INT DEFAULT 0,
    is_published BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES ai_courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB;

-- AI Lessons (curriculum-based)
CREATE TABLE IF NOT EXISTS ai_lessons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    module_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    objectives JSON NULL COMMENT 'Learning objectives for this lesson',
    key_concepts JSON NULL COMMENT 'Main concepts to be taught',
    teaching_notes TEXT NULL COMMENT 'Notes for AI on how to teach this lesson',
    estimated_minutes INT DEFAULT 15,
    difficulty INT DEFAULT 1 COMMENT '1-5 difficulty scale',
    sort_order INT DEFAULT 0,
    is_published BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES ai_modules(id) ON DELETE CASCADE,
    INDEX idx_module (module_id)
) ENGINE=InnoDB;

-- AI Lesson Progress (tracks student progress through AI lessons)
CREATE TABLE IF NOT EXISTS ai_lesson_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    lesson_id INT NOT NULL,
    status ENUM('not_started', 'started', 'in_progress', 'completed') DEFAULT 'not_started',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    time_spent_minutes INT DEFAULT 0,
    messages_exchanged INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES ai_courses(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES ai_lessons(id) ON DELETE CASCADE,
    UNIQUE KEY unique_progress (user_id, lesson_id),
    INDEX idx_user_course (user_id, course_id)
) ENGINE=InnoDB;

-- AI Course Enrollments
CREATE TABLE IF NOT EXISTS ai_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    progress_percent DECIMAL(5,2) DEFAULT 0,
    status ENUM('enrolled', 'in_progress', 'completed') DEFAULT 'enrolled',
    completed_lessons INT DEFAULT 0,
    last_accessed_at DATETIME NULL,
    completed_at DATETIME NULL,
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES ai_courses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (user_id, course_id),
    INDEX idx_user (user_id),
    INDEX idx_course (course_id)
) ENGINE=InnoDB;

-- Update ai_chat_history to link with AI courses/lessons
ALTER TABLE ai_chat_history
ADD COLUMN course_id INT NULL AFTER context,
ADD COLUMN lesson_id INT NULL AFTER course_id,
ADD INDEX idx_course_lesson (course_id, lesson_id);

-- =============================================
-- INSERT SAMPLE AI COURSE (for testing)
-- =============================================

-- Sample AI Course: Python Programming
INSERT INTO ai_courses (title, slug, description, short_description, level, total_lessons, estimated_hours, is_free, is_published, learning_outcomes) VALUES
('Python Programming Fundamentals', 'python-fundamentals',
'Learn Python programming from scratch with our AI tutor. This course covers all the basics you need to start your programming journey, with interactive lessons and personalized guidance.',
'Master Python basics with personalized AI tutoring',
'beginner', 12, 6.00, TRUE, TRUE,
'["Understand Python syntax and basic concepts", "Write simple Python programs", "Work with variables, data types, and operators", "Use control structures (if/else, loops)", "Create and use functions", "Handle basic input/output operations"]'
);

-- Get the course ID
SET @python_course_id = LAST_INSERT_ID();

-- Module 1: Getting Started
INSERT INTO ai_modules (course_id, title, description, sort_order) VALUES
(@python_course_id, 'Getting Started with Python', 'Introduction to Python and setting up your development environment', 1);

SET @module1_id = LAST_INSERT_ID();

INSERT INTO ai_lessons (module_id, title, description, objectives, key_concepts, estimated_minutes, sort_order) VALUES
(@module1_id, 'What is Python?', 'Introduction to Python programming language and its uses',
'["Understand what Python is", "Know why Python is popular", "Learn about Python applications"]',
'["Programming language basics", "Python history", "Python use cases"]', 15, 1),
(@module1_id, 'Setting Up Python', 'Installing Python and setting up your development environment',
'["Install Python on your computer", "Understand Python interpreter", "Write and run your first Python command"]',
'["Python installation", "REPL", "IDE basics"]', 20, 2),
(@module1_id, 'Your First Python Program', 'Writing and running your first Python program',
'["Create a Python file", "Write a simple program", "Run Python programs"]',
'["print() function", "Python files", "Running scripts"]', 15, 3);

-- Module 2: Variables and Data Types
INSERT INTO ai_modules (course_id, title, description, sort_order) VALUES
(@python_course_id, 'Variables and Data Types', 'Learn about storing data in Python', 2);

SET @module2_id = LAST_INSERT_ID();

INSERT INTO ai_lessons (module_id, title, description, objectives, key_concepts, estimated_minutes, sort_order) VALUES
(@module2_id, 'Variables in Python', 'Understanding variables and how to use them',
'["Create and use variables", "Understand variable naming rules", "Assign values to variables"]',
'["Variables", "Assignment operator", "Naming conventions"]', 20, 1),
(@module2_id, 'Numbers and Math', 'Working with numbers and mathematical operations',
'["Use integers and floats", "Perform mathematical operations", "Understand operator precedence"]',
'["int", "float", "arithmetic operators", "order of operations"]', 25, 2),
(@module2_id, 'Strings', 'Working with text data in Python',
'["Create and manipulate strings", "Use string methods", "Format strings"]',
'["Strings", "String concatenation", "String methods", "f-strings"]', 25, 3);

-- Module 3: Control Flow
INSERT INTO ai_modules (course_id, title, description, sort_order) VALUES
(@python_course_id, 'Control Flow', 'Making decisions and repeating code', 3);

SET @module3_id = LAST_INSERT_ID();

INSERT INTO ai_lessons (module_id, title, description, objectives, key_concepts, estimated_minutes, sort_order) VALUES
(@module3_id, 'Conditional Statements', 'Making decisions with if, elif, and else',
'["Write if statements", "Use comparison operators", "Create complex conditions"]',
'["if", "elif", "else", "comparison operators", "logical operators"]', 30, 1),
(@module3_id, 'Loops - Part 1', 'Repeating code with for loops',
'["Use for loops", "Iterate over sequences", "Use range()"]',
'["for loop", "range()", "iteration"]', 25, 2),
(@module3_id, 'Loops - Part 2', 'Repeating code with while loops',
'["Use while loops", "Control loop execution", "Avoid infinite loops"]',
'["while loop", "break", "continue", "loop control"]', 25, 3);

-- Module 4: Functions
INSERT INTO ai_modules (course_id, title, description, sort_order) VALUES
(@python_course_id, 'Functions', 'Creating reusable code blocks', 4);

SET @module4_id = LAST_INSERT_ID();

INSERT INTO ai_lessons (module_id, title, description, objectives, key_concepts, estimated_minutes, sort_order) VALUES
(@module4_id, 'Introduction to Functions', 'Understanding and creating functions',
'["Define functions", "Call functions", "Understand function purpose"]',
'["def keyword", "function definition", "function call", "code reusability"]', 25, 1),
(@module4_id, 'Function Parameters', 'Working with function inputs',
'["Use parameters and arguments", "Set default values", "Use keyword arguments"]',
'["parameters", "arguments", "default values", "keyword arguments"]', 30, 2),
(@module4_id, 'Return Values', 'Getting data back from functions',
'["Return values from functions", "Use return statement", "Handle returned data"]',
'["return statement", "return values", "None"]', 25, 3);

-- Update total_lessons count
UPDATE ai_courses SET total_lessons = 12 WHERE id = @python_course_id;
