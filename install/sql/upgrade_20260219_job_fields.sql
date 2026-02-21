-- 招聘字段扩展：招聘人数、工作性质、学历要求、经验要求
ALTER TABLE yikai_contents ADD COLUMN headcount varchar(20) NOT NULL DEFAULT '' COMMENT '招聘人数' AFTER requirements;
ALTER TABLE yikai_contents ADD COLUMN job_type varchar(20) NOT NULL DEFAULT '' COMMENT '工作性质' AFTER headcount;
ALTER TABLE yikai_contents ADD COLUMN education varchar(50) NOT NULL DEFAULT '' COMMENT '学历要求' AFTER job_type;
ALTER TABLE yikai_contents ADD COLUMN experience varchar(50) NOT NULL DEFAULT '' COMMENT '经验要求' AFTER education;
