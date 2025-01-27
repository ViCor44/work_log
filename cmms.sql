-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 27-Jan-2025 às 20:27
-- Versão do servidor: 10.4.32-MariaDB
-- versão do PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `cmms`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `assets`
--

CREATE TABLE `assets` (
  `id` int(11) NOT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `manual` varchar(255) DEFAULT NULL,
  `qrcode` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `features` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `assets`
--

INSERT INTO `assets` (`id`, `manufacturer`, `name`, `description`, `photo`, `manual`, `qrcode`, `created_at`, `updated_at`, `features`, `category_id`) VALUES
(6, NULL, 'Bomba Elevação Pistas', 'Bomba de elevação das Pistas Foam', 'uploads/uploads/D_NQ_NP_806680-MLB43541020273_092020-O.webp', 'uploads/uploads/1710146787750.pdf', 'uploads/qrcode_6.png', '2024-10-21 21:52:35', '2024-12-03 17:43:13', '400v\r\n20A\r\n20KW', 3),
(7, NULL, 'PCA1 Pistas Brandas', 'Controlador de cloro livre e pH', 'grundfos_did_500x200px.png', 'Grundfosliterature-6511733.pdf', 'uploads/qrcode_7.png', '2024-10-24 22:32:56', '2024-10-24 22:32:57', 'GRUNDFOS DID', NULL),
(11, NULL, 'Grelhador Frangos', 'Grelhador de frangos', 'grelhador-GV3-vertical.jpg', 'Catalogo-UL-2020.pdf', 'uploads/qrcode_11.png', '2024-10-27 20:18:10', '2024-10-27 20:18:10', '400V\r\n22KW\r\n50A', 4),
(12, NULL, 'Placa', 'placa', 'logo_slidesplash.png', '', 'uploads/qrcode_12.png', '2024-11-01 14:41:10', '2024-11-01 14:41:10', 'placa', 4),
(14, NULL, 'Placa', 'placa', 'logo_slidesplash.png', '', 'uploads/qrcode_14.png', '2024-11-01 19:37:40', '2024-11-01 19:37:40', 'placa', 4),
(15, NULL, 'Big Wave Elevação 1', 'Bomba de elevação 1 do Big Wave', 'uploads/assets_20241201/Bomba-De-gua-Para-A-gua-Do-Mar-Alto-Desempenho-Venda-Direta-Da-F-brica-China.avif', 'uploads/assets_20241201/megabloc-servicos.pdf', 'uploads/assets_20241201/qrcode_15.png', '2024-12-01 12:24:44', '2024-12-01 12:24:44', 'Potência - 30KW\r\nIn - 60A\r\nVn - 400V', 5);

-- --------------------------------------------------------

--
-- Estrutura da tabela `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `categories`
--

INSERT INTO `categories` (`id`, `name`, `parent_id`) VALUES
(1, 'Divertimentos', NULL),
(2, 'Restauração', NULL),
(3, 'Casa de Máquinas 1', 1),
(4, 'Quiosque Grelhados', 2),
(5, 'Casa de Máquinas 5', 1);

-- --------------------------------------------------------

--
-- Estrutura da tabela `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message_text` text NOT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `message_text`, `timestamp`, `is_read`) VALUES
(1, 2, 2, 'Teste', '2024-10-22 19:11:28', 1),
(2, 2, 2, 'Recebido', '2024-10-22 19:33:42', 1),
(3, 2, 3, 'Olá! Teste', '2024-10-23 22:48:59', 1),
(4, 2, 3, 'Teste.', '2024-10-24 19:40:09', 0),
(5, 3, 4, 'Novo Teste!', '2024-10-24 22:23:12', 0),
(6, 3, 2, 'Novo Teste!', '2024-10-24 22:23:12', 0);

-- --------------------------------------------------------

--
-- Estrutura da tabela `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `report_type` enum('manutencao','avaria','diario','autoprotecao') NOT NULL DEFAULT 'diario',
  `technician_id` int(11) DEFAULT NULL,
  `report_date` date DEFAULT NULL,
  `execution_date` date DEFAULT NULL,
  `report_details` text CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `edit_date` datetime DEFAULT NULL,
  `pdf_generated` tinyint(1) DEFAULT 0,
  `printed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `reports`
--

INSERT INTO `reports` (`id`, `report_type`, `technician_id`, `report_date`, `execution_date`, `report_details`, `created_at`, `edit_date`, `pdf_generated`, `printed`) VALUES
(2, 'manutencao', 3, '2024-10-25', '2024-10-26', 'Teste user comum', '2024-10-26 22:24:35', NULL, 1, 1),
(3, 'manutencao', 2, '2024-10-25', '2024-10-26', 'Teste admin', '2024-10-26 22:25:52', NULL, 1, 0);

-- --------------------------------------------------------

--
-- Estrutura da tabela `report_photos`
--

CREATE TABLE `report_photos` (
  `id` int(11) NOT NULL,
  `report_id` int(11) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `report_photos`
--

INSERT INTO `report_photos` (`id`, `report_id`, `photo_path`) VALUES
(2, 2, 'uploads/bad mood.png'),
(3, 3, 'uploads/img5e9eab2a5a5e40.85674433.jpg');

-- --------------------------------------------------------

--
-- Estrutura da tabela `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `security_question` varchar(100) NOT NULL DEFAULT 'Cão?',
  `security_answer` varchar(255) NOT NULL DEFAULT 'Piloto',
  `user_type` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `accepted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `users`
--

INSERT INTO `users` (`id`, `username`, `first_name`, `last_name`, `email`, `phone`, `password`, `security_question`, `security_answer`, `user_type`, `created_at`, `updated_at`, `accepted`) VALUES
(2, 'VCorreia', 'Victor', 'Correia', 'victor.a.correia@gmail.com', '967930476', '$2y$10$eygSbJAoWM5IcnXKu1KTTuGNOe8hu/6dtw6oBzqDaDE3b.df7wWXa', 'Gato?', 'Romeo', 'admin', '2024-10-21 20:31:31', '2024-10-26 12:39:20', 1),
(3, 'JLopes', 'António', 'Lopes', 'jlopes@gmail.com', '961234567', '$2y$10$JaXjXjPHjqrxOSVnZgqfnehG/DCQHTqpzjMH.fpZgOMJKx1T7T4WW', 'Gato?', 'Romeu', 'user', '2024-10-23 21:37:00', '2024-10-27 23:03:09', 1),
(4, 'JSavimbi', 'Jonas', 'Savimbi', 'js@gmail.com', '917894563', '$2y$10$qPIBQrIWYmos3nSW4q1KQ.ssIVHO1OFNQYGl4/8HQWoX1YIb7bBeK', 'Cão?', 'Piloto', 'user', '2024-10-24 21:22:20', '2024-10-26 22:36:04', 0),
(5, 'LLOpes', 'Luis', 'Lopes', 'll@gmail.com', '921345678', '$2y$10$.uxAU7iA6KUdHKxAyow/D.P2F.7VOTeXxBCTqSonZnoc31oQy6KtS', 'Cão?', 'Piloto', 'user', '2024-10-26 14:47:33', '2024-10-26 22:37:05', 1),
(6, 'admin', 'admin', 'admin', 'admin@gmail.com', '000000000', '$2y$10$sx6XT.dllEgbFM.CVIsXHOwSr.nfEQYfQ3i8Wb110mWXjero2qbcu', 'Cão?', 'Piloto', 'admin', '2024-12-06 22:29:01', '2024-12-06 22:29:01', 1);

-- --------------------------------------------------------

--
-- Estrutura da tabela `work_orders`
--

CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `status` enum('Pendente','Aceite','Em Andamento','Fechada') DEFAULT 'Pendente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `closed_at` timestamp NULL DEFAULT NULL,
  `due_date` timestamp NULL DEFAULT NULL,
  `assigned_user` int(11) DEFAULT NULL,
  `type` enum('Preventiva','Corretiva') NOT NULL,
  `priority` enum('Crítica','Alta','Média','Baixa') DEFAULT 'Baixa',
  `accept_at` datetime DEFAULT NULL,
  `accept_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Extraindo dados da tabela `work_orders`
--

INSERT INTO `work_orders` (`id`, `asset_id`, `description`, `status`, `created_at`, `closed_at`, `due_date`, `assigned_user`, `type`, `priority`, `accept_at`, `accept_by`) VALUES
(1, 6, 'reparar a fuga', 'Fechada', '2024-10-22 20:55:19', '2024-10-26 09:09:53', NULL, 2, 'Preventiva', 'Crítica', '2024-10-26 09:44:56', 4),
(3, 6, 'Pintar.', 'Fechada', '2024-10-24 19:28:03', '2024-10-26 09:07:40', NULL, 2, 'Preventiva', 'Média', '2024-10-26 10:07:33', 2),
(4, 6, 'Colocar uma rede', 'Fechada', '2024-10-24 19:40:21', '2024-10-27 21:55:10', NULL, 3, 'Corretiva', 'Alta', '2024-10-25 23:26:56', 4),
(5, 6, 'Colocar uma rede', 'Fechada', '2024-10-24 19:43:29', '2024-11-29 10:56:14', NULL, 3, 'Corretiva', 'Baixa', '2024-11-26 19:56:43', 2),
(6, 6, 'Pintar', 'Fechada', '2024-10-24 19:58:40', '2024-10-26 16:17:34', NULL, 4, 'Preventiva', 'Baixa', '2024-10-26 12:57:15', 2),
(12, 7, 'Substituir eletrodo de pH', 'Fechada', '2024-10-26 08:34:41', '2024-11-26 20:19:49', NULL, 2, 'Corretiva', 'Alta', '2024-10-26 11:39:12', 2),
(13, 7, 'Substituir eletrólito', 'Fechada', '2024-10-26 10:59:43', '2024-10-28 22:04:48', NULL, 4, 'Corretiva', 'Crítica', '2024-10-26 17:16:16', 5),
(17, 11, 'Limpeza geral do gralhador', 'Em Andamento', '2024-11-29 09:30:23', NULL, '2024-12-04 18:00:00', 3, 'Preventiva', 'Média', '2024-11-29 11:57:26', 2),
(18, 7, 'Substituir Gel', 'Fechada', '2024-11-29 14:18:42', '2024-11-29 15:19:38', '2024-11-29 23:59:00', 2, 'Corretiva', 'Crítica', NULL, NULL),
(19, 7, 'Substituir Eléctrodo cloro', 'Pendente', '2024-11-29 18:15:20', NULL, '2024-11-29 23:59:00', 5, 'Corretiva', 'Crítica', NULL, NULL);

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Índices para tabela `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Índices para tabela `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`);

--
-- Índices para tabela `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `report_photos`
--
ALTER TABLE `report_photos`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices para tabela `work_orders`
--
ALTER TABLE `work_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `asset_id` (`asset_id`),
  ADD KEY `assigned_user` (`assigned_user`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `assets`
--
ALTER TABLE `assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de tabela `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `report_photos`
--
ALTER TABLE `report_photos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `work_orders`
--
ALTER TABLE `work_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Restrições para despejos de tabelas
--

--
-- Limitadores para a tabela `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Limitadores para a tabela `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`);

--
-- Limitadores para a tabela `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`);

--
-- Limitadores para a tabela `work_orders`
--
ALTER TABLE `work_orders`
  ADD CONSTRAINT `work_orders_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`),
  ADD CONSTRAINT `work_orders_ibfk_2` FOREIGN KEY (`assigned_user`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
