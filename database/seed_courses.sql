-- =============================================
-- LEARNRAIL COURSE SEED DATA
-- Run this after schema.sql
-- =============================================

-- Database is specified in mysql command, no USE statement needed

-- Create default instructor
INSERT INTO instructors (name, bio, title, expertise, is_active) VALUES
('Learnrail Academy', 'The official Learnrail Academy instructor team, bringing you expert-level courses in AI, technology, and professional development.', 'Lead Instructor', '["Artificial Intelligence", "Machine Learning", "Prompt Engineering", "Cybersecurity", "Data Science", "Programming"]', TRUE);

-- Add AI/ML specific category (without color column if not exists)
INSERT INTO categories (name, slug, description, icon, sort_order) VALUES
('Artificial Intelligence', 'artificial-intelligence', 'AI, Machine Learning and Data Science courses', 'brain', 6)
ON DUPLICATE KEY UPDATE name = name;

-- =============================================
-- COURSES
-- =============================================

-- 1. Prompt Engineering
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Prompt Engineering Mastery',
    'prompt-engineering-mastery',
    'Master the art and science of prompt engineering. Learn how to craft effective prompts for AI models like ChatGPT, Claude, and other large language models. This comprehensive course covers everything from basic prompting techniques to advanced strategies for complex tasks.',
    'Learn to craft effective prompts for AI models and unlock the full potential of large language models.',
    1,
    6,
    'beginner',
    12.5,
    FALSE,
    TRUE,
    TRUE,
    '["Basic understanding of AI concepts", "Access to ChatGPT or similar AI tool", "Curiosity and willingness to experiment"]',
    '["Understand how large language models work", "Master basic and advanced prompting techniques", "Create effective prompts for various use cases", "Debug and optimize underperforming prompts", "Build prompt templates for productivity"]',
    '["AI", "ChatGPT", "Claude", "LLM", "Prompts", "GPT-4"]'
);

-- 2. Artificial Intelligence Fundamentals
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Artificial Intelligence Fundamentals',
    'artificial-intelligence-fundamentals',
    'A comprehensive introduction to Artificial Intelligence. Understand the history, concepts, and applications of AI in the modern world. This course covers neural networks, natural language processing, computer vision, and the ethical considerations of AI development.',
    'Comprehensive introduction to AI concepts, applications, and the future of intelligent systems.',
    1,
    6,
    'beginner',
    20.0,
    FALSE,
    TRUE,
    TRUE,
    '["Basic computer literacy", "Interest in technology", "No prior AI knowledge required"]',
    '["Understand what AI is and its various types", "Learn about neural networks and deep learning", "Explore natural language processing", "Understand computer vision applications", "Grasp ethical considerations in AI", "Identify AI use cases in various industries"]',
    '["AI", "Neural Networks", "NLP", "Computer Vision", "Deep Learning"]'
);

-- 3. Machine Learning
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Machine Learning from Scratch',
    'machine-learning-from-scratch',
    'Learn Machine Learning from the ground up. This course covers supervised and unsupervised learning, classification, regression, clustering, and model evaluation. Includes hands-on projects using Python and popular ML libraries like scikit-learn and TensorFlow.',
    'Master machine learning algorithms, techniques, and practical implementation with Python.',
    1,
    6,
    'intermediate',
    35.0,
    FALSE,
    TRUE,
    TRUE,
    '["Basic Python programming", "Understanding of basic mathematics", "Familiarity with statistics helpful but not required"]',
    '["Understand ML fundamentals and algorithms", "Implement supervised learning models", "Build unsupervised learning solutions", "Evaluate and optimize models", "Work with real-world datasets", "Use scikit-learn and TensorFlow"]',
    '["Machine Learning", "Python", "scikit-learn", "TensorFlow", "Data Science"]'
);

-- 4. Data Analyst
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Data Analyst Professional',
    'data-analyst-professional',
    'Become a professional Data Analyst. Learn data collection, cleaning, analysis, and visualization techniques. Master tools like Excel, SQL, Python, and Tableau. This course prepares you for a career in data analytics with practical projects and real-world scenarios.',
    'Complete data analyst training covering Excel, SQL, Python, and data visualization tools.',
    1,
    1,
    'beginner',
    40.0,
    FALSE,
    TRUE,
    TRUE,
    '["Basic computer skills", "Interest in data and numbers", "No prior programming experience required"]',
    '["Master data collection and cleaning techniques", "Write SQL queries for data analysis", "Use Python for data manipulation", "Create compelling data visualizations", "Build dashboards in Tableau", "Analyze and interpret business data", "Present insights to stakeholders"]',
    '["Data Analysis", "SQL", "Python", "Excel", "Tableau", "Visualization"]'
);

-- 5. Cybersecurity
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Cybersecurity Essentials',
    'cybersecurity-essentials',
    'Learn the fundamentals of cybersecurity. Understand threats, vulnerabilities, and defense strategies. This course covers network security, encryption, ethical hacking basics, security best practices, and compliance frameworks. Prepare for a career in information security.',
    'Master cybersecurity fundamentals, threat prevention, and security best practices.',
    1,
    1,
    'beginner',
    30.0,
    FALSE,
    TRUE,
    TRUE,
    '["Basic networking knowledge helpful", "Interest in security", "Access to a computer for labs"]',
    '["Understand cybersecurity fundamentals", "Identify common threats and vulnerabilities", "Implement security best practices", "Learn about encryption and cryptography", "Understand network security", "Explore ethical hacking concepts", "Learn compliance frameworks"]',
    '["Cybersecurity", "Network Security", "Ethical Hacking", "Security", "InfoSec"]'
);

-- 6. AI Agent Automation
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'AI Agent Automation',
    'ai-agent-automation',
    'Learn to build and deploy AI agents for task automation. This advanced course covers autonomous AI agents, multi-agent systems, LangChain, AutoGPT concepts, and practical automation workflows. Build AI assistants that can research, plan, and execute complex tasks.',
    'Build autonomous AI agents that can research, plan, and automate complex workflows.',
    1,
    6,
    'advanced',
    25.0,
    FALSE,
    TRUE,
    TRUE,
    '["Understanding of AI and LLMs", "Basic Python programming", "Familiarity with APIs", "Prior prompt engineering knowledge recommended"]',
    '["Understand AI agent architectures", "Build autonomous task agents", "Implement multi-agent systems", "Use LangChain for agent development", "Create custom automation workflows", "Deploy and monitor AI agents", "Handle agent memory and context"]',
    '["AI Agents", "Automation", "LangChain", "AutoGPT", "LLM", "Python"]'
);

-- 7. Programming Languages
INSERT INTO courses (title, slug, description, short_description, instructor_id, category_id, level, duration_hours, is_free, is_featured, is_published, requirements, what_you_learn, tags) VALUES
(
    'Programming Languages Masterclass',
    'programming-languages-masterclass',
    'A comprehensive introduction to programming covering multiple languages. Learn Python, JavaScript, and foundational programming concepts that transfer across languages. Perfect for beginners who want to understand programming fundamentals and choose their specialization path.',
    'Learn programming fundamentals across Python, JavaScript, and other essential languages.',
    1,
    1,
    'beginner',
    45.0,
    FALSE,
    TRUE,
    TRUE,
    '["No prior programming experience needed", "Basic computer skills", "Dedication to practice coding"]',
    '["Understand programming fundamentals", "Write code in Python", "Build with JavaScript", "Understand data structures", "Learn problem-solving techniques", "Choose your programming career path", "Build portfolio projects"]',
    '["Programming", "Python", "JavaScript", "Coding", "Software Development"]'
);

-- =============================================
-- MODULES (Basic structure for each course)
-- =============================================

-- Modules for Prompt Engineering (Course ID: 1)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(1, 'Introduction to Prompt Engineering', 'Understanding LLMs and the basics of prompting', 1),
(1, 'Basic Prompting Techniques', 'Zero-shot, few-shot, and chain-of-thought prompting', 2),
(1, 'Advanced Prompting Strategies', 'Complex prompts, personas, and structured outputs', 3),
(1, 'Prompt Optimization', 'Debugging, testing, and improving prompts', 4),
(1, 'Real-World Applications', 'Practical use cases and project work', 5);

-- Modules for AI Fundamentals (Course ID: 2)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(2, 'What is Artificial Intelligence?', 'History, definitions, and types of AI', 1),
(2, 'Machine Learning Basics', 'Introduction to ML concepts within AI', 2),
(2, 'Neural Networks & Deep Learning', 'Understanding how neural networks work', 3),
(2, 'Natural Language Processing', 'How AI understands and generates text', 4),
(2, 'Computer Vision', 'How AI sees and interprets images', 5),
(2, 'AI Ethics and Future', 'Ethical considerations and future trends', 6);

-- Modules for Machine Learning (Course ID: 3)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(3, 'ML Fundamentals', 'Core concepts and mathematics behind ML', 1),
(3, 'Data Preprocessing', 'Cleaning and preparing data for ML', 2),
(3, 'Supervised Learning', 'Classification and regression algorithms', 3),
(3, 'Unsupervised Learning', 'Clustering and dimensionality reduction', 4),
(3, 'Model Evaluation', 'Metrics, validation, and optimization', 5),
(3, 'Deep Learning Introduction', 'Neural networks with TensorFlow', 6),
(3, 'ML Projects', 'Hands-on projects and portfolio building', 7);

-- Modules for Data Analyst (Course ID: 4)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(4, 'Introduction to Data Analytics', 'What data analysts do and career paths', 1),
(4, 'Excel for Data Analysis', 'Advanced Excel techniques and formulas', 2),
(4, 'SQL Fundamentals', 'Database queries and data extraction', 3),
(4, 'Python for Data Analysis', 'Pandas, NumPy, and data manipulation', 4),
(4, 'Data Visualization', 'Creating charts and visual stories', 5),
(4, 'Tableau & Dashboards', 'Building interactive dashboards', 6),
(4, 'Capstone Project', 'End-to-end data analysis project', 7);

-- Modules for Cybersecurity (Course ID: 5)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(5, 'Cybersecurity Fundamentals', 'Core concepts and threat landscape', 1),
(5, 'Network Security', 'Protecting network infrastructure', 2),
(5, 'Cryptography Basics', 'Encryption and data protection', 3),
(5, 'Application Security', 'Securing web and mobile applications', 4),
(5, 'Ethical Hacking Introduction', 'Penetration testing basics', 5),
(5, 'Security Operations', 'Incident response and monitoring', 6),
(5, 'Compliance & Governance', 'Security frameworks and standards', 7);

-- Modules for AI Agent Automation (Course ID: 6)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(6, 'Introduction to AI Agents', 'What are AI agents and how they work', 1),
(6, 'Agent Architectures', 'Design patterns for autonomous agents', 2),
(6, 'LangChain Framework', 'Building agents with LangChain', 3),
(6, 'Memory & Context Management', 'Handling agent memory and state', 4),
(6, 'Multi-Agent Systems', 'Coordinating multiple agents', 5),
(6, 'Deployment & Monitoring', 'Production deployment strategies', 6);

-- Modules for Programming Languages (Course ID: 7)
INSERT INTO modules (course_id, title, description, sort_order) VALUES
(7, 'Programming Fundamentals', 'Core concepts across all languages', 1),
(7, 'Python Basics', 'Getting started with Python', 2),
(7, 'Python Intermediate', 'Functions, OOP, and modules', 3),
(7, 'JavaScript Basics', 'Introduction to web programming', 4),
(7, 'JavaScript DOM & Events', 'Interactive web development', 5),
(7, 'Data Structures', 'Essential data structures explained', 6),
(7, 'Algorithms & Problem Solving', 'Coding challenges and solutions', 7),
(7, 'Career Paths', 'Choosing your programming specialization', 8);

-- =============================================
-- SAMPLE LESSONS (Placeholder for each module)
-- Video URLs will be added via admin panel later
-- =============================================

-- Add intro lesson for each module (Module IDs 1-43)
-- Prompt Engineering modules (1-5)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(1, 'Welcome to Prompt Engineering', 'Course overview and what you will learn', 'video', TRUE, 1),
(1, 'How Large Language Models Work', 'Understanding the technology behind AI chatbots', 'video', FALSE, 2),
(2, 'Zero-Shot Prompting', 'Getting results without examples', 'video', FALSE, 1),
(2, 'Few-Shot Prompting', 'Using examples to guide AI responses', 'video', FALSE, 2),
(3, 'Chain-of-Thought Prompting', 'Making AI show its reasoning', 'video', FALSE, 1),
(3, 'Role-Based Prompting', 'Using personas for better outputs', 'video', FALSE, 2),
(4, 'Testing Your Prompts', 'Systematic prompt evaluation', 'video', FALSE, 1),
(5, 'Building a Prompt Library', 'Creating reusable prompt templates', 'video', FALSE, 1);

-- AI Fundamentals modules (6-11)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(6, 'What is AI?', 'Definition and history of artificial intelligence', 'video', TRUE, 1),
(6, 'Types of AI', 'Narrow AI, General AI, and Super AI', 'video', FALSE, 2),
(7, 'Introduction to Machine Learning', 'How machines learn from data', 'video', FALSE, 1),
(8, 'How Neural Networks Work', 'The building blocks of deep learning', 'video', FALSE, 1),
(9, 'Understanding NLP', 'How AI processes human language', 'video', FALSE, 1),
(10, 'Computer Vision Basics', 'How AI interprets images', 'video', FALSE, 1),
(11, 'AI Ethics Overview', 'Responsible AI development', 'video', FALSE, 1);

-- ML modules (12-18)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(12, 'Machine Learning Overview', 'What is ML and why it matters', 'video', TRUE, 1),
(13, 'Data Cleaning Techniques', 'Preparing data for analysis', 'video', FALSE, 1),
(14, 'Linear Regression', 'Predicting continuous values', 'video', FALSE, 1),
(14, 'Classification Algorithms', 'Categorizing data points', 'video', FALSE, 2),
(15, 'K-Means Clustering', 'Grouping similar data', 'video', FALSE, 1),
(16, 'Model Evaluation Metrics', 'Measuring model performance', 'video', FALSE, 1),
(17, 'Introduction to TensorFlow', 'Building neural networks', 'video', FALSE, 1),
(18, 'ML Project Setup', 'Starting your first project', 'video', FALSE, 1);

-- Data Analyst modules (19-25)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(19, 'What is Data Analytics?', 'Role and responsibilities of data analysts', 'video', TRUE, 1),
(20, 'Excel Formulas Deep Dive', 'Essential formulas for analysis', 'video', FALSE, 1),
(21, 'SQL SELECT Statements', 'Querying databases', 'video', FALSE, 1),
(21, 'SQL JOINs Explained', 'Combining data from multiple tables', 'video', FALSE, 2),
(22, 'Introduction to Pandas', 'Python data manipulation library', 'video', FALSE, 1),
(23, 'Chart Types and When to Use Them', 'Choosing the right visualization', 'video', FALSE, 1),
(24, 'Tableau Basics', 'Creating your first dashboard', 'video', FALSE, 1),
(25, 'Capstone Project Introduction', 'Project requirements and setup', 'video', FALSE, 1);

-- Cybersecurity modules (26-32)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(26, 'Cybersecurity Landscape', 'Understanding the threat environment', 'video', TRUE, 1),
(26, 'CIA Triad', 'Confidentiality, Integrity, Availability', 'video', FALSE, 2),
(27, 'Network Security Fundamentals', 'Protecting network infrastructure', 'video', FALSE, 1),
(28, 'Encryption Basics', 'How encryption protects data', 'video', FALSE, 1),
(29, 'Web Application Security', 'Common vulnerabilities and prevention', 'video', FALSE, 1),
(30, 'Penetration Testing Introduction', 'Ethical hacking overview', 'video', FALSE, 1),
(31, 'Security Monitoring', 'Detecting and responding to threats', 'video', FALSE, 1),
(32, 'Security Frameworks', 'NIST, ISO 27001, and compliance', 'video', FALSE, 1);

-- AI Agent modules (33-38)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(33, 'What are AI Agents?', 'Understanding autonomous AI systems', 'video', TRUE, 1),
(33, 'Agent vs Assistant', 'Key differences and use cases', 'video', FALSE, 2),
(34, 'ReAct Pattern', 'Reasoning and Acting framework', 'video', FALSE, 1),
(35, 'LangChain Setup', 'Getting started with LangChain', 'video', FALSE, 1),
(35, 'Building Your First Agent', 'Hands-on agent creation', 'video', FALSE, 2),
(36, 'Memory Types in Agents', 'Short-term vs long-term memory', 'video', FALSE, 1),
(37, 'Agent Communication', 'How agents work together', 'video', FALSE, 1),
(38, 'Deploying AI Agents', 'Production considerations', 'video', FALSE, 1);

-- Programming modules (39-46)
INSERT INTO lessons (module_id, title, description, type, is_free_preview, sort_order) VALUES
(39, 'Why Learn Programming?', 'Career opportunities and possibilities', 'video', TRUE, 1),
(39, 'Programming Concepts Overview', 'Variables, loops, and functions', 'video', FALSE, 2),
(40, 'Python Installation & Setup', 'Setting up your development environment', 'video', FALSE, 1),
(40, 'Your First Python Program', 'Writing Hello World and beyond', 'video', FALSE, 2),
(41, 'Python Functions', 'Creating reusable code blocks', 'video', FALSE, 1),
(41, 'Object-Oriented Programming', 'Classes and objects in Python', 'video', FALSE, 2),
(42, 'JavaScript Introduction', 'The language of the web', 'video', FALSE, 1),
(43, 'DOM Manipulation', 'Making web pages interactive', 'video', FALSE, 1),
(44, 'Arrays and Lists', 'Storing collections of data', 'video', FALSE, 1),
(45, 'Problem Solving Strategies', 'Breaking down coding challenges', 'video', FALSE, 1),
(46, 'Choosing Your Path', 'Web, mobile, data, or AI?', 'video', FALSE, 1);

-- Update course lesson counts
UPDATE courses SET total_lessons = (SELECT COUNT(*) FROM lessons l JOIN modules m ON l.module_id = m.id WHERE m.course_id = courses.id);

SELECT 'Seed data inserted successfully!' AS status;
SELECT CONCAT('Total courses created: ', COUNT(*)) AS courses FROM courses;
SELECT CONCAT('Total modules created: ', COUNT(*)) AS modules FROM modules;
SELECT CONCAT('Total lessons created: ', COUNT(*)) AS lessons FROM lessons;
