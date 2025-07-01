import os

d = 'downloads/lama_dataset/masks'
with open('masks_list.txt', 'w', encoding='utf-8') as out:
    out.write('Абсолютный путь: ' + os.path.abspath(d) + '\n')
    exists = os.path.exists(d)
    out.write('Существует: ' + str(exists) + '\n')
    files = os.listdir(d) if exists else []
    out.write('Всего файлов: ' + str(len(files)) + '\n')
    for f in files:
        file_path = os.path.join(d, f)
        out.write(f'{file_path} | isfile: {os.path.isfile(file_path)} | size: {os.path.getsize(file_path)}\n')
print('Список файлов записан в masks_list.txt') 