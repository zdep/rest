#
# Table structure for table users
#

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT='Имя юзера',
  `email` varchar(50) NOT NULL DEFAULT '' COMMENT='Мыло',
  `age` int DEFAULT NULL COMMENT='Возраст',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Юзеры';
INSERT INTO `users` VALUES (1,'test','111',222);
INSERT INTO `users` VALUES (2,'test2','222',11);
