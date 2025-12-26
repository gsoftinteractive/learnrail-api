-- Migration: Add color column to categories table
-- Run this on the live database to fix the 500 error

ALTER TABLE categories ADD COLUMN color VARCHAR(20) NULL DEFAULT '#6366F1' AFTER icon;

-- Update existing categories with colors
UPDATE categories SET color = '#3B82F6' WHERE slug = 'technology';
UPDATE categories SET color = '#10B981' WHERE slug = 'business';
UPDATE categories SET color = '#8B5CF6' WHERE slug = 'personal-development';
UPDATE categories SET color = '#F59E0B' WHERE slug = 'creative';
UPDATE categories SET color = '#EF4444' WHERE slug = 'health-wellness';
