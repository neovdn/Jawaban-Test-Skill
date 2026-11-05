-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2026 at 04:34 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `test_it`
--
CREATE DATABASE IF NOT EXISTS `test_it` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `test_it`;

-- --------------------------------------------------------

--
-- Table structure for table `msbarang`
--

DROP TABLE IF EXISTS `msbarang`;
CREATE TABLE `msbarang` (
  `KODE_BARANG` varchar(5) NOT NULL,
  `NAMA_BARANG` varchar(50) NOT NULL,
  `SATUAN` varchar(5) NOT NULL,
  `KATEGORI` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `msbarang`
--

INSERT INTO `msbarang` (`KODE_BARANG`, `NAMA_BARANG`, `SATUAN`, `KATEGORI`) VALUES
('R001', 'FLAT BAR', 'KG', 'RAWMAT'),
('R002', 'STEEL WIRE', 'KG', 'RAWMAT'),
('S001', 'KLIP', 'BOX', 'SUBMAT'),
('S002', 'RIVET', 'PCS', 'SUBMAT'),
('S003', 'PALET', 'PCS', 'SUBMAT');

-- --------------------------------------------------------

--
-- Table structure for table `trmutasidt`
--

DROP TABLE IF EXISTS `trmutasidt`;
CREATE TABLE `trmutasidt` (
  `NO_TRANSAKSI` varchar(5) NOT NULL,
  `KODE_BARANG` varchar(5) NOT NULL,
  `QTY` int(11) NOT NULL
) ;

--
-- Dumping data for table `trmutasidt`
--

INSERT INTO `trmutasidt` (`NO_TRANSAKSI`, `KODE_BARANG`, `QTY`) VALUES
('T001', 'S001', 10),
('T001', 'S002', 4),
('T001', 'S003', 6),
('T002', 'S001', 4),
('T002', 'S002', 1),
('T002', 'S003', 2),
('T003', 'S002', 6),
('T003', 'S003', 2),
('T004', 'R001', 10),
('T004', 'R002', 5),
('T005', 'R001', 5);

-- --------------------------------------------------------

--
-- Table structure for table `trmutasihd`
--

DROP TABLE IF EXISTS `trmutasihd`;
CREATE TABLE `trmutasihd` (
  `NO_TRANSAKSI` varchar(5) NOT NULL,
  `TANGGAL` date NOT NULL,
  `JENIS_TRANSAKSI` varchar(10) NOT NULL,
  `KATEGORI` varchar(10) NOT NULL
) ;

--
-- Dumping data for table `trmutasihd`
--

INSERT INTO `trmutasihd` (`NO_TRANSAKSI`, `TANGGAL`, `JENIS_TRANSAKSI`, `KATEGORI`) VALUES
('T001', '2021-01-01', 'MASUK', 'SUBMAT'),
('T002', '2021-01-05', 'KELUAR', 'SUBMAT'),
('T003', '2021-02-10', 'MASUK', 'SUBMAT'),
('T004', '2021-01-10', 'MASUK', 'RAWMAT'),
('T005', '2021-01-25', 'KELUAR', 'RAWMAT');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `msbarang`
--
ALTER TABLE `msbarang`
  ADD PRIMARY KEY (`KODE_BARANG`);

--
-- Indexes for table `trmutasidt`
--
ALTER TABLE `trmutasidt`
  ADD PRIMARY KEY (`NO_TRANSAKSI`,`KODE_BARANG`),
  ADD KEY `fk_dt_barang` (`KODE_BARANG`);

--
-- Indexes for table `trmutasihd`
--
ALTER TABLE `trmutasihd`
  ADD PRIMARY KEY (`NO_TRANSAKSI`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `trmutasidt`
--
ALTER TABLE `trmutasidt`
  ADD CONSTRAINT `fk_dt_barang` FOREIGN KEY (`KODE_BARANG`) REFERENCES `msbarang` (`KODE_BARANG`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dt_hd` FOREIGN KEY (`NO_TRANSAKSI`) REFERENCES `trmutasihd` (`NO_TRANSAKSI`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
