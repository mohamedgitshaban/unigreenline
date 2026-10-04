<?php

/**
 * Static city reference lists, keyed by country then governorate.
 *
 * @return array<string, array<string, list<string>>>
 */
return [
    'egypt' => [
        'Cairo' => ['Cairo', 'New Cairo', 'Nasr City', 'Heliopolis', 'Maadi', 'Helwan', '15th of May', 'Badr', 'Shorouk', 'New Administrative Capital'],
        'Giza' => ['Giza', '6th of October', 'Sheikh Zayed', 'Haram', 'Imbaba', 'Hawamdeya', 'Badrashin', 'Ayat', 'Atfeh', 'Saff', 'Kerdasa', 'Oseem', 'Abu Nomros', 'Bahariya'],
        'Alexandria' => ['Alexandria', 'Borg El Arab', 'New Borg El Arab', 'Amreya', 'Montaza', 'Agami'],
        'Qalyubia' => ['Banha', 'Shubra El Kheima', 'Qalyub', 'Khanka', 'Obour', 'Khosous', 'Shibin El Qanater', 'Tukh', 'Qaha', 'Kafr Shukr'],
        'Sharqia' => ['Zagazig', '10th of Ramadan', 'Belbeis', 'Minya El Qamh', 'Abu Hammad', 'Abu Kabir', 'Faqous', 'Hehya', 'Kafr Saqr', 'Mashtool El Souk', 'Diyarb Negm', 'Husseiniya', 'Awlad Saqr', 'Ibrahimiya', 'Salhiya'],
        'Dakahlia' => ['Mansoura', 'Talkha', 'Mit Ghamr', 'Dekernes', 'Aga', 'Sinbillawin', 'Belqas', 'Sherbin', 'Manzala', 'Matariya', 'Minyat El Nasr', 'Gamasa', 'Temay El Amdeed', 'Nabaroh', 'Bani Ebeid', 'Mit Salsil', 'Gamaliya'],
        'Gharbia' => ['Tanta', 'El Mahalla El Kubra', 'Kafr El Zayat', 'Zefta', 'Samanoud', 'Santa', 'Qutur', 'Basyoun'],
        'Monufia' => ['Shibin El Kom', 'Sadat City', 'Menouf', 'Ashmoun', 'Quesna', 'Berket El Sab', 'Tala', 'Shohada', 'Bagour', 'Sers El Layan'],
        'Beheira' => ['Damanhour', 'Kafr El Dawwar', 'Rashid', 'Edku', 'Abu El Matamir', 'Abu Hummus', 'Delengat', 'Mahmoudiya', 'Rahmaniya', 'Itay El Barud', 'Hosh Essa', 'Shubrakhit', 'Kom Hamada', 'Wadi El Natrun', 'Badr', 'Nubariya'],
        'Kafr El Sheikh' => ['Kafr El Sheikh', 'Desouk', 'Fuwwah', 'Metoubes', 'Baltim', 'Sidi Salem', 'Qallin', 'Hamool', 'Biyala', 'Riyadh'],
        'Damietta' => ['Damietta', 'New Damietta', 'Ras El Bar', 'Faraskur', 'Kafr Saad', 'Zarqa', 'Kafr El Battikh'],
        'Port Said' => ['Port Said', 'Port Fouad'],
        'Ismailia' => ['Ismailia', 'Fayed', 'Qantara Sharq', 'Qantara Gharb', 'Tell El Kebir', 'Abu Suwir', 'Qassasin'],
        'Suez' => ['Suez', 'Ain Sokhna'],
        'Fayoum' => ['Fayoum', 'New Fayoum', 'Sinnuris', 'Tamiya', 'Itsa', 'Ibshaway', 'Yusuf El Seddiq'],
        'Beni Suef' => ['Beni Suef', 'New Beni Suef', 'Wasta', 'Nasser', 'Ihnasya', 'Beba', 'Fashn', 'Somosta'],
        'Minya' => ['Minya', 'New Minya', 'Mallawi', 'Samalut', 'Maghagha', 'Beni Mazar', 'Matai', 'Abu Qurqas', 'Deir Mawas', 'Edwa'],
        'Asyut' => ['Asyut', 'New Asyut', 'Dairut', 'Manfalut', 'Qusiya', 'Abnub', 'Abu Tig', 'Ghanaim', 'Sahel Selim', 'Badari', 'Sidfa', 'Fath'],
        'Sohag' => ['Sohag', 'New Sohag', 'Akhmim', 'Girga', 'Tahta', 'Tama', 'Maragha', 'Juhayna', 'Saqultah', 'Monshaa', 'Dar El Salam', 'Balyana', 'Usayrat'],
        'Qena' => ['Qena', 'New Qena', 'Nag Hammadi', 'Qus', 'Dishna', 'Abu Tesht', 'Farshut', 'Waqf', 'Naqada', 'Qift'],
        'Luxor' => ['Luxor', 'New Luxor', 'Esna', 'Armant', 'Tiba', 'Qurna', 'Bayadiya', 'Zeiniya', 'Toud'],
        'Aswan' => ['Aswan', 'New Aswan', 'Kom Ombo', 'Edfu', 'Daraw', 'Nasr El Nuba', 'Kalabsha', 'Abu Simbel', 'Radisiya'],
        'Red Sea' => ['Hurghada', 'Safaga', 'El Quseir', 'Marsa Alam', 'Ras Gharib', 'Shalateen', 'Halaib', 'El Gouna'],
        'New Valley' => ['Kharga', 'Dakhla', 'Farafra', 'Baris', 'Balat'],
        'Matrouh' => ['Marsa Matrouh', 'El Alamein', 'New Alamein', 'El Dabaa', 'Sidi Barrani', 'Salloum', 'Siwa', 'Hammam'],
        'North Sinai' => ['Arish', 'Sheikh Zuweid', 'Rafah', 'Bir El Abd', 'Hasana', 'Nakhl'],
        'South Sinai' => ['El Tor', 'Sharm El Sheikh', 'Dahab', 'Nuweiba', 'Taba', 'Saint Catherine', 'Ras Sedr', 'Abu Zenima', 'Abu Rudeis'],
    ],
];
